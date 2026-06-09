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
        $port = (int) Manifest::get('tasks.web.port', 8000);

        if (! Manifest::usesOctane()) {
            return sprintf('frankenphp php-server --listen 0.0.0.0:%d --root public/', $port);
        }

        return sprintf('php artisan octane:start --host=0.0.0.0 --port=%d', $port);
    }

    public static function queue(): string
    {
        return 'php artisan queue:work --tries=3 --max-time=3600';
    }

    /**
     * The scheduler runs as cron (busybox crond firing schedule:run each minute),
     * not a long-lived schedule:work daemon — so the trigger halts cleanly on
     * shutdown and only the in-flight run is waited out (see the entrypoint drain).
     */
    public static function scheduler(): string
    {
        return 'crond -f -d 8 -c /app/docker/crontabs';
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
