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
    /**
     * The web process. `octane:start` is the server-agnostic launcher — it boots
     * whichever Octane server OCTANE_SERVER names (YOLO injects frankenphp by
     * default, matching the scaffolded Dockerfile's base image). Using the generic
     * command rather than the dedicated octane:frankenphp keeps the configured
     * server the single source of truth, so config('octane.server') can never
     * disagree with the server actually running.
     */
    public static function octane(): string
    {
        return sprintf(
            'php artisan octane:start --host=0.0.0.0 --port=%d',
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
}
