<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

/**
 * The shell commands for each workload process, in one place so the bundled
 * supervisord config (GenerateSupervisorConfigStep) and the standalone-service
 * entrypoint (GenerateEntrypointScriptStep) can't drift — a change to the
 * queue-worker flags or the scheduler's cron invocation lands in both at once.
 *
 * Octane is the web process; the queue worker and the scheduler run either as
 * supervisord programs inside the web container (bundled) or as the sole process
 * of their own service (standalone), but the command is identical either way.
 */
class ProcessCommands
{
    /**
     * The in-image path of the Caddyfile YOLO generates into the build context at
     * docker/Caddyfile (GenerateSupervisorConfigStep) and runs the web server against.
     * Octane needs it for the metrics global option; classic mode always needs it for
     * the thread bounds and carries the same option when autoscaling. One path serves
     * both. Absolute because supervisord's working directory is not contractual.
     */
    public const string CADDYFILE = '/app/docker/Caddyfile';

    /**
     * The web process. By default Octane: `octane:start` is the server-agnostic
     * launcher — it boots whichever Octane server OCTANE_SERVER names. That var is
     * the app's to own (it pairs with the Dockerfile's base image; `yolo init`
     * seeds it to frankenphp) — YOLO deliberately does not inject it, since the
     * server is a developer choice, not YOLO-provisioned infrastructure. Using the
     * generic command rather than the dedicated octane:frankenphp keeps the
     * configured server the single source of truth, so config('octane.server') can
     * never disagree with the server actually running.
     *
     * With `tasks.web.octane: false` the tier runs FrankenPHP in classic mode
     * instead — per-request boot, no resident app — for an app that isn't
     * Octane-safe yet. That's the same frankenphp binary the base image already
     * ships, independent of laravel/octane, so it serves even when the app has no
     * Octane package; only the launch command differs.
     *
     * Classic mode runs `frankenphp run` against a generated Caddyfile rather than
     * the simpler `frankenphp php-server`, because php-server exposes no way to
     * configure the thread pool: it has no thread flag, and it does not read a
     * Caddyfile — so the FRANKENPHP_CONFIG env var the base image's own Caddyfile
     * threads into the `frankenphp` global option is inert on that path. Its pool is
     * therefore fixed at 2 × the CPUs visible to the process, which on Fargate is the
     * microVM's ~2 vCPUs regardless of task size. A Caddyfile is the only channel
     * that can set the bounds at all; see {@see WebThreads}.
     */
    public static function web(): string
    {
        if (! Manifest::usesOctane()) {
            return 'frankenphp run --config ' . self::CADDYFILE;
        }

        $command = 'php artisan octane:start --host=0.0.0.0 --port=8000';

        // Burst autoscaling reads FrankenPHP's worker metrics, which Octane only exposes
        // when Caddy metrics are enabled. octane:start rebuilds CADDY_GLOBAL_OPTIONS for
        // the frankenphp child from a fixed whitelist, discarding whatever value the task
        // sets — so a container env var can't switch metrics on. The surviving channel is
        // a custom Caddyfile: GenerateSupervisorConfigStep writes the app's own Octane stub
        // with the top-level `metrics` global option added to docker/Caddyfile, and
        // --caddyfile runs it.
        // Web autoscaling only. Classic mode returned above: its generated Caddyfile
        // carries the same option and is already the file `run --config` loads.
        if (Manifest::usesMetricsCaddyfile()) {
            $command .= ' --caddyfile=' . self::CADDYFILE;
        }

        // Pin the worker pool to the task's real vCPU allocation rather than letting
        // FrankenPHP auto-detect it off the Fargate microVM's fixed ~2 vCPUs (which
        // would pin ~4 workers on every task whatever its size — too few for an
        // I/O-blocking request, and the concurrency ceiling per task). See {@see WebWorkers}.
        $command .= ' --workers=' . WebWorkers::count();

        return $command;
    }

    /**
     * The queue worker. A solo app runs the bare command against the pinned
     * SQS_QUEUE; a multi-tenant app (and any app declaring a `queues:` block) passes
     * an explicit `--queue=` value so one program drains one scope's queues — the
     * per-tenant/per-tier fan-out GenerateSupervisorConfigStep builds. A comma list
     * drains strict-priority (high before default), which is the intra-scope
     * priority feature; fairness across scopes comes from a separate program each.
     */
    public static function queue(?string $queue = null): string
    {
        $command = 'php artisan queue:work --tries=3 --max-time=3600';

        return $queue === null ? $command : "{$command} --queue={$queue}";
    }

    /**
     * The scheduler runs as cron (supercronic firing schedule:run each minute),
     * not a long-lived schedule:work daemon — so the trigger halts cleanly on
     * shutdown and only the in-flight run is waited out (see
     * ShutdownTimings::stopTimeoutFor for the budget that wait lives in).
     *
     * supercronic is the one cron that works here: the container runs everything
     * as www-data, and busybox crond can't run cron as a non-root user — it
     * silently ignores crontabs not owned by root, and its forked job children
     * die on a setgroups EPERM before exec. supercronic is built for exactly this
     * (jobs run as the invoking user, no identity switch), captures job output to
     * its own stdout/stderr — which supervisord already routes to the container
     * log — and on SIGTERM stops scheduling and waits out the in-flight run, so
     * its stop IS the drain. CheckSchedulerRuntimeStep gates the build on the
     * binary being present in the image.
     */
    public static function scheduler(): string
    {
        return 'supercronic /app/docker/crontab';
    }

    /**
     * Inertia's SSR renderer — a Node process PHP calls over 127.0.0.1:13714 on
     * each render. It's bundled in the web container (never its own service) so
     * the call stays on localhost; a dead renderer degrades Inertia to
     * client-side rendering rather than taking the app down.
     */
    public static function ssr(): string
    {
        return 'php artisan inertia:start-ssr';
    }

    /**
     * The in-container backup executor invocation, shared by the generated
     * crontab entry and `backup:database`'s one-off task override so the
     * scheduled and on-demand paths can't drift. Everything the executor needs
     * is baked in as arguments from the manifest — no runtime config, nothing
     * for the app to know about. Tenant ids are the database names (the same
     * contract the per-tenant queue fan-out relies on).
     *
     * @return array<int, string>
     */
    public static function databaseBackup(): array
    {
        $arguments = [
            '--destination=' . Paths::s3BackupsBucket() . '/' . Manifest::name(),
            '--region=' . Manifest::get('region'),
        ];

        if (Manifest::isMultitenanted()) {
            $arguments[] = '--tenants=' . implode(',', array_keys(Manifest::tenants()));
        }

        return ['php', 'artisan', 'yolo:backup-database', ...$arguments];
    }
}
