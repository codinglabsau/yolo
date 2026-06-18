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
 *
 * The web group runs a two-tier CPU-priority order so the kernel scheduler
 * arbitrates contention in the app's favour (nice only bites when CPU is
 * saturated — a no-op with spare capacity, so steady-state throughput is
 * unchanged):
 *
 *     web ≈ ssr ≈ saturation (default nice)  >  queue, scheduler (niced down)
 *
 *  - The request path (web + ssr) AND the burst saturation emitter all run at the
 *    DEFAULT priority. The emitter must never outrank web: it reports worker
 *    saturation by scraping the web server's own metrics endpoint (localhost:2019)
 *    with a short timeout, failing safe to silence on a miss — so prioritising it
 *    ABOVE the very process it scrapes starves that scrape under a CPU pin (web
 *    can't answer in time → null → no emit → burst never trips, the exact failure
 *    this once caused). The invariant: the emitter is ≤ the priority of web (peer
 *    here), never above it. Being I/O-bound — a 5s sleep, then a quick scrape +
 *    put — the kernel schedules the just-woken emitter promptly even at peer
 *    priority, while web at the default priority stays responsive to the scrape, so
 *    neither needs lifting.
 *  - Background work (queue worker, scheduler) is niced well below the request
 *    path when co-located with it, so a heavy job can't starve the web tier.
 *
 * The in-container gauge is best-effort: on a single hard-pinned task nothing
 * in-container can escape a ~99% CPU pin, so the emitter can go silent WITH the box
 * — the target-tracking policies and `min ≥ 2` / more task CPU are the guarantees,
 * burst only sharpens the light-pin / multi-task case. Do not "fix" that silence by
 * lifting the emitter above web; that reintroduces the scrape starvation above.
 *
 * See config() / niceLevel() for the rendering.
 */
class GenerateSupervisorConfigStep implements Step
{
    /**
     * The nice the background programs (queue worker, scheduler) launch at when
     * they share a container with the web server, so the kernel scheduler favours
     * the request path under CPU contention. +19 is the lowest priority an
     * unprivileged user can set — background work effectively yields to web when
     * CPU is saturated, yet still runs at full speed when it isn't. This reallocates
     * CPU, it doesn't cap it: a queue/scheduler burst still shows on the CloudWatch
     * CPU metric, it just no longer hits web latency. On a min-1 combined app,
     * sustained web saturation that starves the queue is itself a scale-out signal
     * the queue-depth alarm already covers.
     */
    private const int BACKGROUND_NICE = 19;

    /**
     * The programs niced below the web server when bundled alongside it.
     *
     * @var list<string>
     */
    private const array BACKGROUND_PROGRAMS = ['scheduler', 'queue'];

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

        // The crontab supercronic reads is generated wherever the scheduler runs —
        // every app unless cron is switched off entirely (tasks.scheduler: false), where
        // schedulerHost() is null and no container runs supercronic, so it's skipped.
        if (Manifest::schedulerHost() instanceof ServerGroup) {
            $this->writeCrontab();
        }

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

        // The web server's presence in this container is what makes it the request
        // path — programGraces only emits a 'web' grace for the web group — so it is
        // exactly the co-location trigger for nicing the background programs below.
        $colocatesWebServer = isset($graces['web']);

        // The burst saturation emitter rides only the web container, and only when the
        // web tier autoscales — this flag adds its program block below. It does NOT nice
        // the request path: web, ssr and the emitter all stay at the default priority, so
        // the emitter never outranks the web server it scrapes (see the class docblock).
        $bundlesEmitter = $group === ServerGroup::WEB && Manifest::isAutoscaling();

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
                $blocks[] = $this->program($program, $this->command($program, $colocatesWebServer), stopwaitsecs: $graces[$program]);
            }
        }

        // The burst saturation emitter (web container only) — a stateless loop that
        // needs no drain window, so a 1s stop wait is plenty. It runs at the default
        // priority, never niced and never lifted above web: it scrapes the web server's
        // own metrics endpoint, so it must not outrank the process it reads (class docblock).
        if ($bundlesEmitter) {
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
            '{{threshold}}' => (string) WebBurstPolicy::ALARM_THRESHOLD,
            '{{cooldown}}' => (string) WebBurstPolicy::COOLDOWN,
            '{{interval}}' => (string) WebBurstPolicy::POLL_INTERVAL,
            '{{namespace}}' => WebBurstPolicy::METRIC_NAMESPACE,
            '{{metric}}' => WebBurstPolicy::METRIC_NAME,
            '{{dimension}}' => WebBurstPolicy::METRIC_DIMENSION,
            '{{region}}' => Manifest::get('region'),
        ]);

        $path = Paths::build('docker/yolo-saturation.php');
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $script);
    }

    /**
     * Write the web Caddyfile into the build context for an autoscaling Octane app.
     * Burst scaling needs FrankenPHP's worker metrics, which Caddy only collects when
     * its top-level `metrics` global option is set — and octane:start rebuilds the
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
     * Add Caddy's top-level `metrics` global option to a Caddyfile. This — not the
     * per-server `servers { metrics }` form — is what registers FrankenPHP's worker
     * gauges (frankenphp_busy_threads / _total_threads) into the global registry the
     * `/metrics` endpoint serves; the per-server form only surfaces caddy_http_* and
     * leaves the burst signal dark. A Caddyfile's global options block is its leading
     * block — the only one whose opener is a bare `{` — so the directive is inserted just
     * inside it. A stub that already enables metrics (a future Octane, or a re-run) is
     * returned untouched; a stub with no global block hard-fails rather than silently
     * shipping a metrics-less Caddyfile that would leave burst scaling dark.
     */
    protected function injectMetrics(string $caddyfile): string
    {
        if (preg_match('/^\s*metrics\b/m', $caddyfile) === 1) {
            return $caddyfile;
        }

        $count = 0;
        $injected = preg_replace('/^\{$/m', "{\n\tmetrics", $caddyfile, 1, $count);

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

    /**
     * The shell command for a program's `command=` line, wrapped in `nice` to place
     * it in the web group's priority order (see the class docblock). The container
     * runs as www-data and every nice applied here is positive (lowering priority),
     * which needs no privilege — so no CAP_SYS_NICE or task-def ulimit is involved.
     */
    protected function command(string $program, bool $colocatesWebServer): string
    {
        $command = ProcessCommands::{$program}();
        $nice = $this->niceLevel($program, $colocatesWebServer);

        return $nice === null
            ? $command
            : sprintf('nice -n %d %s', $nice, $command);
    }

    /**
     * The nice a program launches at, or null for the default priority. Only the
     * background tier (queue worker, scheduler) is niced, and only when it shares a
     * container with the web server, so a heavy job can't starve the request path.
     * Everything else — web, ssr and the saturation emitter — runs at the default
     * priority; the emitter is deliberately NOT lifted above web (it scrapes web's
     * metrics endpoint, so outranking web would starve the scrape under a pin — see
     * the class docblock).
     */
    protected function niceLevel(string $program, bool $colocatesWebServer): ?int
    {
        if ($colocatesWebServer && in_array($program, self::BACKGROUND_PROGRAMS, true)) {
            return self::BACKGROUND_NICE;
        }

        return null;
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
