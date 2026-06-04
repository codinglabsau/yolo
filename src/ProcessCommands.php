<?php

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
    public static function octane(): string
    {
        return sprintf(
            'php artisan octane:frankenphp --host=0.0.0.0 --port=%d',
            (int) Manifest::get('tasks.web.port', 8000),
        );
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
