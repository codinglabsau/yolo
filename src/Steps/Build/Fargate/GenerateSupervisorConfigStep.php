<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use RuntimeException;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\ProcessCommands;
use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\YoloServiceProvider;

/**
 * Generates each container's supervisord.conf into the build context from the
 * manifest — the same way GenerateEntrypointScriptStep generates the entrypoint,
 * so the running processes can't drift from the manifest and the web port
 * is hardcoded to 8000 (the same value the target group health-checks).
 *
 * Which program runs where is derived from task presence (Manifest::queueHost /
 * schedulerHost), not flags. The web container always runs the web server (plus the SSR
 * renderer when tasks.web.ssr is on, and the queue worker / scheduler unless
 * they've been extracted). A standalone queue that also hosts the scheduler runs
 * two processes (queue:work + supercronic), so it gets its own supervisord.queue.conf; a
 * queue-only or scheduler-only standalone service runs a single process the
 * entrypoint exec's directly, with no supervisord config. The web config stays at
 * the path the scaffolded Dockerfile copies (docker/supervisord.conf) — a web-less
 * app writes a comment-only placeholder there to keep that COPY satisfied; the
 * queue config rides along under docker/ via the Dockerfile's `COPY . /app`.
 *
 * When background work (scheduler, queue) shares the web container, it's niced below
 * the request path so the kernel scheduler favours the request path under CPU
 * contention — a heavy job can't starve the web tier. Burst detection now rides the
 * web request itself ({@see YoloServiceProvider}), so web is the top priority; the
 * scheduler (a brief, time-sensitive cron tick) outranks the queue (heavy, backlog-
 * tolerant). nice only bites when CPU is saturated, so steady-state throughput is
 * unchanged:
 *
 *     web ≈ ssr (default)  >  scheduler (nice 10)  >  queue (nice 19)
 *
 * See config() / niceLevel() for the rendering.
 */
class GenerateSupervisorConfigStep implements Step
{
    /**
     * The nice each co-located background program launches at when it shares a
     * container with the web server, so the kernel scheduler favours the request path
     * under CPU contention. Burst detection now rides the web request itself, so web is
     * the top priority (default, never niced); among the background tier the scheduler —
     * a brief, time-sensitive cron tick — outranks the queue, which is heavy and
     * backlog-tolerant (and has its own queue-depth scaling). The order is
     * web > scheduler > queue. All values are positive (an unprivileged www-data can
     * only nice *down*, so no CAP_SYS_NICE or task-def ulimit), and nice reallocates CPU
     * under saturation rather than capping it — a burst still shows on the CloudWatch CPU
     * metric, it just no longer hits web latency.
     *
     * @var array<string, int>
     */
    private const array PROGRAM_NICE = ['scheduler' => 10, 'queue' => 19];

    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        // The web container always runs supervisord (the web server + whatever it
        // hosts). A web-less app has no container that runs this config, but the
        // scaffolded Dockerfile unconditionally COPYs it to /etc/supervisord.conf,
        // so a placeholder is written to keep that contract.
        Manifest::hasWeb()
            ? $this->writeConfig('docker/supervisord.conf', ServerGroup::WEB)
            : $this->writePlaceholderConfig('docker/supervisord.conf');

        // The metrics Caddyfile additionally needs Octane (worker mode) — its worker
        // gauges are the burst signal, and octane:start runs it via --caddyfile
        // (ProcessCommands::web). The shared gate keeps generation, the flag and the
        // build preflight (CheckMetricsRuntimeStep) in lock-step.
        if (Manifest::usesMetricsCaddyfile()) {
            $this->writeCaddyfile();
        }

        // A standalone queue needs supervisord when it runs more than one process:
        // when it co-hosts the scheduler (queue:work + supercronic), OR when it fans
        // queues out per tenant (one queue:work program per tenant + landlord — a
        // single exec'd process can't fan out). A solo or shared-queue service runs a
        // single exec'd worker (no config), dispatched directly by the entrypoint.
        if (Manifest::schedulerHost() === ServerGroup::QUEUE
            || (Manifest::hasStandaloneQueue() && Manifest::fansQueuesPerTenant())) {
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
     * A comment-only stand-in for the web supervisord config on a web-less app.
     * No container ever runs it — a standalone queue/scheduler is a single exec'd
     * process (or supervisord.queue.conf when the queue co-hosts the scheduler) —
     * but the scaffolded Dockerfile COPYs this exact path, so it must exist.
     */
    protected function writePlaceholderConfig(string $relativePath): void
    {
        $path = Paths::build($relativePath);
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put(
            $path,
            "# Auto-generated by YOLO. This app runs no web container; this file only\n"
            . "# satisfies the Dockerfile's COPY and is never used at runtime.\n"
        );
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
        //  - queue     queue:work, with a longer stop wait so an in-flight job can finish.
        //              A multi-tenant app fans this into one program per scope
        //              (landlord + each tenant), each draining that scope's queues —
        //              see queuePrograms(); everything else is a single 'queue' block.
        foreach (['web', 'ssr', 'scheduler', 'queue'] as $program) {
            if (! isset($graces[$program])) {
                continue;
            }

            if ($program === 'queue') {
                foreach ($this->queuePrograms() as $name => $chain) {
                    $blocks[] = $this->program($name, $this->queueCommand($chain, $colocatesWebServer), stopwaitsecs: $graces['queue']);
                }

                continue;
            }

            $blocks[] = $this->program($program, $this->command($program, $colocatesWebServer), stopwaitsecs: $graces[$program]);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * The queue-worker program(s) for this container, as `[program name => --queue
     * chain]`. A solo app runs one `queue` program (bare, or a tier chain when it
     * declares `queues:`); a multi-tenant app runs one program per scope —
     * `queue_landlord` plus `queue_<tenant>` — each draining only that scope's
     * queues, so a whale tenant's backlog can't starve the others (the fairness the
     * naive `--queue=t1,t2,…` comma list would lose to strict priority). Each
     * program's chain comes from Helpers::queueChain, the same source the SQS queues
     * are provisioned from, so the worker never polls a queue that wasn't created.
     *
     * @return array<string, string|null>
     */
    protected function queuePrograms(): array
    {
        // Solo and shared-queue apps run a single program draining the app's own queue
        // set (a bare worker, or a tier chain); only a dedicated multi-tenant app fans
        // into one program per scope.
        if (! Manifest::fansQueuesPerTenant()) {
            return ['queue' => Helpers::queueChain()];
        }

        $programs = ['queue_landlord' => Helpers::queueChain('landlord')];

        foreach (array_keys(Manifest::tenants()) as $tenantId) {
            $programs["queue_{$tenantId}"] = Helpers::queueChain($tenantId);
        }

        return $programs;
    }

    /**
     * A queue program's command line: `queue:work` (with the scope's `--queue`
     * chain) niced into the background tier when it shares the web container. Every
     * per-scope program niced the same — they're all the queue tier — so the lookup
     * is keyed on 'queue', not the fanned-out program name.
     */
    protected function queueCommand(?string $chain, bool $colocatesWebServer): string
    {
        $command = ProcessCommands::queue($chain);
        $nice = $this->niceLevel('queue', $colocatesWebServer);

        return $nice === null
            ? $command
            : sprintf('nice -n %d %s', $nice, $command);
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
     * background tier (scheduler, queue) is niced, and only when it shares a container
     * with the web server, so a heavy job can't starve the request path — scheduler
     * above queue (see {@see PROGRAM_NICE}). Everything else — web and ssr — runs at
     * the default priority.
     */
    protected function niceLevel(string $program, bool $colocatesWebServer): ?int
    {
        return $colocatesWebServer ? (self::PROGRAM_NICE[$program] ?? null) : null;
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
