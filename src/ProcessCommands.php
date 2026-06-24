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
     */
    public static function web(): string
    {
        if (! Manifest::usesOctane()) {
            return 'frankenphp php-server --listen 0.0.0.0:8000 --root public/';
        }

        $command = 'php artisan octane:start --host=0.0.0.0 --port=8000';

        // Burst autoscaling reads FrankenPHP's worker metrics, which Octane only exposes
        // when Caddy metrics are enabled. octane:start rebuilds CADDY_GLOBAL_OPTIONS for
        // the frankenphp child from a fixed whitelist, discarding whatever value the task
        // sets — so a container env var can't switch metrics on. The surviving channel is
        // a custom Caddyfile: GenerateSupervisorConfigStep writes the app's own Octane stub
        // with the top-level `metrics` global option added to docker/Caddyfile, and
        // --caddyfile runs it.
        // Web autoscaling only; classic mode returned above and never reaches here.
        if (Manifest::usesMetricsCaddyfile()) {
            $command .= ' --caddyfile=/app/docker/Caddyfile';
        }

        // Pin the worker pool to the task's real vCPU allocation rather than letting
        // FrankenPHP auto-detect it off the Fargate microVM's fixed ~2 vCPUs (which
        // would pin ~4 workers on every task whatever its size — too few for an
        // I/O-blocking request, and the concurrency ceiling per task). See {@see WebWorkers}.
        $command .= ' --workers=' . WebWorkers::count();

        return $command;
    }

    public static function queue(): string
    {
        return 'php artisan queue:work --tries=3 --max-time=3600';
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
}
