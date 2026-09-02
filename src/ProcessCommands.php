<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

/**
 * One place for every workload's shell command, so the bundled supervisord config and the
 * standalone-service entrypoint can't drift. The queue worker and scheduler run identically
 * whether bundled in the web container or as their own service.
 */
class ProcessCommands
{
    /**
     * Generated into the build context at docker/Caddyfile. Octane needs it for the metrics
     * global option; classic mode always needs it for the thread bounds and carries the same
     * option when autoscaling. Absolute because supervisord's working directory is not
     * contractual.
     */
    public const string CADDYFILE = '/app/docker/Caddyfile';

    /**
     * `octane:start` (not `octane:frankenphp`) so OCTANE_SERVER — the app's to own, seeded by
     * `yolo init` — stays the single source of truth for which server runs. Classic mode
     * (`tasks.web.octane: false`) runs the base image's own frankenphp binary, independent of
     * laravel/octane, via `frankenphp run` against a Caddyfile rather than `php-server`: the
     * latter has no thread flag and ignores FRANKENPHP_CONFIG, fixing its pool at 2 × the
     * microVM's ~2 visible vCPUs whatever the task size. See {@see WebThreads}.
     */
    public static function web(): string
    {
        if (! Manifest::usesOctane()) {
            return 'frankenphp run --config ' . self::CADDYFILE;
        }

        $command = 'php artisan octane:start --host=0.0.0.0 --port=8000';

        // octane:start rebuilds CADDY_GLOBAL_OPTIONS from a fixed whitelist, so a container
        // env var can't switch on the Caddy metrics burst autoscaling reads — only a custom
        // Caddyfile can. Classic mode returned above: its generated Caddyfile already carries
        // the option.
        if (Manifest::usesMetricsCaddyfile()) {
            $command .= ' --caddyfile=' . self::CADDYFILE;
        }

        // FrankenPHP would otherwise auto-detect off the microVM's fixed ~2 vCPUs (~4 workers
        // whatever the task size). See {@see WebWorkers}.
        $command .= ' --workers=' . WebWorkers::count();

        return $command;
    }

    /**
     * A comma list drains strict-priority (high before default) within one scope; fairness
     * across scopes comes from a separate program each.
     */
    public static function queue(?string $queue = null): string
    {
        $command = 'php artisan queue:work --tries=3 --max-time=3600';

        return $queue === null ? $command : "{$command} --queue={$queue}";
    }

    /**
     * Cron, not `schedule:work`, so the trigger halts cleanly on shutdown and only the in-flight
     * run is waited out. supercronic because the container runs as www-data and busybox crond
     * can't run cron as a non-root user (it silently ignores non-root crontabs and its job
     * children die on a setgroups EPERM); supercronic also captures job output to its own
     * stdout and, on SIGTERM, stops scheduling and waits out the in-flight run — its stop IS the drain.
     */
    public static function scheduler(): string
    {
        return 'supercronic /app/docker/crontab';
    }

    /**
     * Bundled in the web container so the 127.0.0.1:13714 call stays on localhost; a dead
     * renderer degrades Inertia to client-side rendering rather than taking the app down.
     */
    public static function ssr(): string
    {
        return 'php artisan inertia:start-ssr';
    }

    /**
     * Shared by the generated crontab entry and `backup:database`'s one-off task so the two
     * paths can't drift. Tenant ids are the database names (the per-tenant queue contract).
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
