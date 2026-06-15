<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use RuntimeException;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\ProcessCommands;
use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * Generates each container's supervisord.conf into the build context from the
 * manifest — the same way GenerateEntrypointScriptStep generates the entrypoint,
 * so the running processes can't drift from the manifest and the web port
 * always matches tasks.web.port (the same value the target group health-checks).
 *
 * Which program runs where is derived from task presence (Manifest::queueHost /
 * schedulerHost), not flags. The web container always runs the web server (plus the SSR
 * renderer when tasks.web.ssr is on, and the queue worker / scheduler unless
 * they've been extracted). A standalone queue that also hosts the scheduler runs
 * two processes (queue:work + supercronic), so it gets its own supervisord.queue.conf; a
 * queue-only or scheduler-only standalone service runs a single process the
 * entrypoint exec's directly, with no supervisord config. The web config stays at
 * the path the scaffolded Dockerfile copies (docker/supervisord.conf); the queue
 * config rides along under docker/ via the Dockerfile's `COPY . /app`.
 */
class GenerateSupervisorConfigStep implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        // The web container always runs supervisord (the web server + whatever it hosts).
        $this->writeConfig('docker/supervisord.conf', ServerGroup::WEB);

        // The saturation emitter rides with the burst alarm — present wherever the web
        // tier autoscales (its supervisord program is added by config() for the same
        // gate). In classic mode no metrics ever flow, so it's an inert no-op there.
        if (Manifest::isAutoscaling()) {
            $this->writeEmitterScript();
        }

        // The metrics Caddyfile additionally needs Octane (worker mode) — its worker
        // gauges are the burst signal, and octane:start runs it via --caddyfile
        // (ProcessCommands::web). The shared gate keeps generation, the flag and the
        // build preflight (CheckMetricsRuntimeStep) in lock-step.
        if (Manifest::usesMetricsCaddyfile()) {
            $this->writeCaddyfile();
        }

        // A standalone queue only needs supervisord when it co-hosts the scheduler
        // (queue:work + supercronic = two processes). A queue-only service runs a single
        // exec'd process — no config — so the entrypoint dispatches it directly.
        if (Manifest::schedulerHost() === ServerGroup::QUEUE) {
            $this->writeConfig('docker/supervisord.queue.conf', ServerGroup::QUEUE);
        }

        // The scheduler runs cron in some container of every app, so the crontab it
        // reads is always generated (the standalone scheduler service runs supercronic too).
        $this->writeCrontab();

        return StepResult::SUCCESS;
    }

    protected function writeConfig(string $relativePath, ServerGroup $group): void
    {
        $path = Paths::build($relativePath);
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $this->config($group, ShutdownTimings::programGraces($group)));
    }

    /**
     * @param  array<string, int>  $graces
     */
    protected function config(ServerGroup $group, array $graces): string
    {
        $blocks = [$this->header()];

        // One block per program present in this container, in a stable order. Stop
        // waits come from ShutdownTimings so a program's graceful-stop window matches
        // the container stopTimeout derived from the same source.
        //  - web       the web server — Octane (FrankenPHP worker mode) by default,
        //              or FrankenPHP classic mode when tasks.web.octane is off
        //              (ProcessCommands::web); web container only
        //  - ssr       Inertia's Node renderer beside the web server; autorestart brings
        //              it back if it crashes, and Inertia renders client-side while it's down
        //  - scheduler supercronic firing an ephemeral schedule:run each minute (not a
        //              schedule:work daemon — the trigger halts cleanly on shutdown)
        //  - queue     queue:work, with a longer stop wait so an in-flight job can finish
        foreach (['web', 'ssr', 'scheduler', 'queue'] as $program) {
            if (isset($graces[$program])) {
                $blocks[] = $this->program($program, ProcessCommands::{$program}(), stopwaitsecs: $graces[$program]);
            }
        }

        // The burst saturation emitter (web container only) — a stateless loop
        // that needs no drain window, so a 1s stop wait is plenty.
        if ($group === ServerGroup::WEB && Manifest::isAutoscaling()) {
            $blocks[] = $this->program('saturation', ProcessCommands::saturationEmitter(), stopwaitsecs: 1);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * Render the saturation emitter from its stub, substituting this app's web
     * service name (the metric's dimension value) and the metric contract it shares
     * with {@see WebBurstPolicy}'s alarm, into the build context.
     */
    protected function writeEmitterScript(): void
    {
        $script = strtr((string) $this->filesystem->get(Paths::stubs('yolo-saturation.php.stub')), [
            '{{service}}' => WebBurstPolicy::serviceName(),
            '{{floor}}' => (string) WebBurstPolicy::EMIT_FLOOR,
            '{{namespace}}' => WebBurstPolicy::METRIC_NAMESPACE,
            '{{metric}}' => WebBurstPolicy::METRIC_NAME,
            '{{dimension}}' => WebBurstPolicy::METRIC_DIMENSION,
        ]);

        $path = Paths::build('docker/yolo-saturation.php');
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $script);
    }

    /**
     * Write the web Caddyfile into the build context for an autoscaling Octane app.
     * Burst scaling needs FrankenPHP's worker metrics, which Caddy only collects when
     * the `servers { metrics }` global option is set — and octane:start rebuilds the
     * CADDY_GLOBAL_OPTIONS env var YOLO would use to inject it, so a task env var can't
     * turn metrics on. The surviving channel is a custom Caddyfile passed to
     * octane:start via --caddyfile (ProcessCommands::web). Rather than carry a full copy
     * of Octane's stub (which would drift across Octane versions), this reads the app's
     * OWN installed stub and adds only the one metrics line, so it stays in lock-step
     * with whatever Octane the image ships.
     */
    protected function writeCaddyfile(): void
    {
        $stub = Paths::base('vendor/laravel/octane/src/Commands/stubs/Caddyfile');

        if (! $this->filesystem->exists($stub)) {
            throw new RuntimeException(
                'Build aborted: web autoscaling needs FrankenPHP metrics, enabled through a '
                . 'Caddyfile built from Octane\'s stub — but '
                . 'vendor/laravel/octane/src/Commands/stubs/Caddyfile is missing. Run '
                . '`composer install` so laravel/octane is present before building, or turn '
                . 'web autoscaling off.'
            );
        }

        $path = Paths::build('docker/Caddyfile');
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $this->injectMetrics((string) $this->filesystem->get($stub)));
    }

    /**
     * Add the `servers { metrics }` global option to a Caddyfile. A Caddyfile's global
     * options block is its leading block — the only one whose opener is a bare `{` — so
     * the directive is inserted just inside it. A stub that already enables metrics (a
     * future Octane, or a re-run) is returned untouched; a stub with no global block
     * hard-fails rather than silently shipping a metrics-less Caddyfile that would leave
     * burst scaling dark.
     */
    protected function injectMetrics(string $caddyfile): string
    {
        if (preg_match('/servers\s*\{[^}]*\bmetrics\b/s', $caddyfile) === 1) {
            return $caddyfile;
        }

        $count = 0;
        $injected = preg_replace('/^\{$/m', "{\n\tservers {\n\t\tmetrics\n\t}", $caddyfile, 1, $count);

        if ($injected === null || $count !== 1) {
            throw new RuntimeException(
                'Build aborted: could not find the Caddyfile global options block to enable '
                . 'FrankenPHP metrics (Octane\'s stub format may have changed). Refusing to '
                . 'ship a build that would silently leave burst scaling dark.'
            );
        }

        return $injected;
    }

    /**
     * The crontab supercronic reads (ProcessCommands::scheduler points at it).
     * Jobs inherit the container's environment and supercronic captures their
     * output itself, so the entry needs no PATH override or fd redirect.
     */
    protected function writeCrontab(): void
    {
        $path = Paths::build('docker/crontab');

        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put(
            $path,
            "# Auto-generated by YOLO. Fires the Laravel scheduler once a minute.\n"
            . "* * * * * cd /app && php artisan schedule:run\n"
        );
    }

    protected function header(): string
    {
        return implode("\n", [
            '# Auto-generated by YOLO from yolo.yml — do not edit.',
            '[supervisord]',
            'nodaemon=true',
            'user=www-data',
            'pidfile=/tmp/supervisord.pid',
            'logfile=/dev/null',
            'logfile_maxbytes=0',
            '',
            '[unix_http_server]',
            'file=/tmp/supervisor.sock',
            '',
            '[supervisorctl]',
            'serverurl=unix:///tmp/supervisor.sock',
            '',
            '[rpcinterface:supervisor]',
            'supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface',
        ]);
    }

    protected function program(string $name, string $command, int $stopwaitsecs = 10): string
    {
        return implode("\n", [
            "[program:$name]",
            "command=$command",
            'autostart=true',
            'autorestart=true',
            "stopwaitsecs=$stopwaitsecs",
            'stdout_logfile=/dev/fd/1',
            'stdout_logfile_maxbytes=0',
            'stderr_logfile=/dev/fd/2',
            'stderr_logfile_maxbytes=0',
        ]);
    }
}
