<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateSupervisorConfigStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    if (is_file(Paths::build('docker/supervisord.conf'))) {
        unlink(Paths::build('docker/supervisord.conf'));
    }
    if (is_file(Paths::build('docker/supervisord.queue.conf'))) {
        unlink(Paths::build('docker/supervisord.queue.conf'));
    }
    if (is_file(Paths::build('docker/crontab'))) {
        unlink(Paths::build('docker/crontab'));
    }
    if (is_file(Paths::build('docker/yolo-saturation.php'))) {
        unlink(Paths::build('docker/yolo-saturation.php'));
    }
    if (is_file(Paths::build('docker/Caddyfile'))) {
        unlink(Paths::build('docker/Caddyfile'));
    }

    // GenerateSupervisorConfigStep builds the metrics Caddyfile from the app's installed
    // Octane stub; the fixture app has no real vendor/, so plant a representative one for
    // the autoscaling cases. The stub-missing case deletes it.
    plantOctaneCaddyfileStub();
});

function plantOctaneCaddyfileStub(): void
{
    $stub = Paths::base('vendor/laravel/octane/src/Commands/stubs/Caddyfile');

    if (! is_dir(dirname($stub))) {
        mkdir(dirname($stub), 0755, true);
    }

    file_put_contents($stub, <<<'STUB'
{
	{$CADDY_GLOBAL_OPTIONS}

	admin {$CADDY_SERVER_ADMIN_HOST}:{$CADDY_SERVER_ADMIN_PORT}

	frankenphp {
		worker {
			file "{$APP_PUBLIC_PATH}/frankenphp-worker.php"
			{$CADDY_SERVER_WORKER_DIRECTIVE}
			{$CADDY_SERVER_WATCH_DIRECTIVES}
		}
	}
}

{$CADDY_SERVER_SERVER_NAME} {
	route {
		root * "{$APP_PUBLIC_PATH}"
		php_server
	}
}
STUB);
}

function generatedSupervisorConfig(): string
{
    (new GenerateSupervisorConfigStep('testing'))();

    return file_get_contents(Paths::build('docker/supervisord.conf'));
}

it('runs octane on the manifest port by default', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['port' => 9000]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:web]');
    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=9000');
});

it('defaults the octane port to 8000', function (): void {
    expect(generatedSupervisorConfig())->toContain('--port=8000');
});

it('runs frankenphp classic mode on the manifest port when tasks.web.octane is false', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false, 'port' => 9000]],
    ]);

    $config = generatedSupervisorConfig();

    // Same web program slot, classic-mode command — no octane:start, no worker boot.
    expect($config)->toContain('[program:web]');
    expect($config)->toContain('command=frankenphp php-server --listen 0.0.0.0:9000 --root public/');
    expect($config)->not->toContain('octane:start');
});

it('bundles octane, the scheduler and the queue worker into the web config for a plain web app', function (): void {
    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:web]');
    expect($config)->toContain('[program:scheduler]');
    // The scheduler runs as cron, not a schedule:work daemon.
    expect($config)->toContain('command=supercronic /app/docker/crontab');
    expect($config)->not->toContain('schedule:work');
    expect($config)->toContain('[program:queue]');
    expect($config)->toContain('command=php artisan queue:work --tries=3 --max-time=3600');
});

it('runs octane alone in the web config when both queue and scheduler are extracted', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:web]');
    expect($config)->not->toContain('[program:scheduler]');
    expect($config)->not->toContain('[program:queue]');
});

it('drops the scheduler from the web config when it has its own service, keeping the bundled queue', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'scheduler' => []],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:web]');
    expect($config)->toContain('[program:queue]');
    expect($config)->not->toContain('[program:scheduler]');
});

it('writes a second supervisord config for a standalone queue that hosts the scheduler', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // Web container: octane only (queue + scheduler extracted onto the queue).
    $web = file_get_contents(Paths::build('docker/supervisord.conf'));
    expect($web)->toContain('[program:web]');
    expect($web)->not->toContain('[program:queue]');
    expect($web)->not->toContain('[program:scheduler]');

    // Queue container: queue worker + supercronic, no octane.
    $queue = file_get_contents(Paths::build('docker/supervisord.queue.conf'));
    expect($queue)->toContain('[program:queue]');
    expect($queue)->toContain('[program:scheduler]');
    expect($queue)->not->toContain('[program:web]');
});

it('writes no queue supervisord config when the standalone queue is a single process', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // Queue-only and scheduler-only services run a single exec'd process — no config.
    expect(is_file(Paths::build('docker/supervisord.queue.conf')))->toBeFalse();
});

it('writes a crontab firing schedule:run each minute (the scheduler always runs somewhere)', function (): void {
    (new GenerateSupervisorConfigStep('testing'))();

    $crontab = file_get_contents(Paths::build('docker/crontab'));

    // The whole entry: supercronic jobs inherit the container env and have their
    // output captured, so no PATH override or fd redirect rides along.
    expect($crontab)->toContain("* * * * * cd /app && php artisan schedule:run\n");
});

it('uses the web shutdown-grace-period for octanes stop wait', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 25], 'queue' => [], 'scheduler' => []],
    ]);

    // Octane is the only program in the web config here (queue + scheduler extracted),
    // so the only stopwaitsecs is its own.
    expect(generatedSupervisorConfig())->toContain('stopwaitsecs=25');
});

it('honours a standalone queue shutdown-grace-period override', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => ['shutdown-grace-period' => 90], 'scheduler' => []],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // A queue-only standalone service has no supervisord config of its own; the
    // worker's grace surfaces as the queue task's stopTimeout, not here.
    expect(is_file(Paths::build('docker/supervisord.queue.conf')))->toBeFalse();
});

it('runs the inertia ssr renderer when tasks.web.ssr is enabled', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:ssr]');
    expect($config)->toContain('command=php artisan inertia:start-ssr');
    // Stateless renderer → short stop wait.
    expect($config)->toContain('stopwaitsecs=5');
});

it('does not run the ssr renderer by default', function (): void {
    expect(generatedSupervisorConfig())->not->toContain('[program:ssr]');
});

it('omits the saturation emitter program and script when burst is off', function (): void {
    // beforeEach clears any prior script; the default manifest has no burst.
    expect(generatedSupervisorConfig())->not->toContain('[program:saturation]');
    expect(is_file(Paths::build('docker/yolo-saturation.php')))->toBeFalse();
});

it('adds the saturation emitter program and writes its script for an Octane autoscaling app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 2, 'max' => 8]]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:saturation]');
    expect($config)->toContain('command=php /app/docker/yolo-saturation.php');

    // The emitter is rendered from its stub with the metric contract + this app's
    // web service name substituted in — and no leftover placeholder tokens.
    $script = file_get_contents(Paths::build('docker/yolo-saturation.php'));
    expect($script)
        ->toContain("\$service = 'yolo-testing-my-app-web';")
        ->toContain("'Namespace' => 'YOLO/Autoscaling'")
        ->toContain("'Name' => 'WorkerSaturation'")
        ->toContain("'ServiceName' => \$service")
        ->toContain('$floor = 70;')
        ->toContain('frankenphp_busy_threads')
        ->not->toContain('{{');

    // The rendered emitter is runtime PHP — gate its syntax so a stub edit that
    // breaks it is caught here, not on a deploy.
    exec('php -l ' . escapeshellarg(Paths::build('docker/yolo-saturation.php')), $output, $code);
    expect($code)->toBe(0);
});

it('generates a metrics-enabled Caddyfile from the app Octane stub for an autoscaling app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 2, 'max' => 8]]],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    $caddyfile = file_get_contents(Paths::build('docker/Caddyfile'));

    // It's the app's own Octane stub (its placeholders intact, so Octane still fills
    // them) with only the top-level `metrics` global option added — not a takeover, and
    // not the per-server `servers { metrics }` form (that surfaces caddy_http_* but
    // leaves FrankenPHP's worker gauges — the burst signal — dark).
    expect($caddyfile)
        ->toMatch('/^\s*metrics\s*$/m')
        ->toContain('{$CADDY_GLOBAL_OPTIONS}')
        ->toContain('frankenphp {')
        ->not->toContain('servers {');
});

it('runs octane against the metrics Caddyfile via --caddyfile when autoscaling', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);

    expect(generatedSupervisorConfig())
        ->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000 --caddyfile=/app/docker/Caddyfile');
});

it('writes no Caddyfile and passes no --caddyfile when the web tier is not autoscaling', function (): void {
    // The default manifest is octane without autoscaling.
    $config = generatedSupervisorConfig();

    expect($config)->not->toContain('--caddyfile');
    expect(is_file(Paths::build('docker/Caddyfile')))->toBeFalse();
});

it('writes no Caddyfile in classic mode even when autoscaling', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false, 'autoscaling' => true]],
    ]);

    $config = generatedSupervisorConfig();

    // Classic mode runs frankenphp php-server, not octane:start — no Caddyfile, no flag.
    expect($config)->toContain('command=frankenphp php-server');
    expect($config)->not->toContain('--caddyfile');
    expect(is_file(Paths::build('docker/Caddyfile')))->toBeFalse();
});

it('hard-fails the build when the Octane Caddyfile stub is missing for an autoscaling app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);

    unlink(Paths::base('vendor/laravel/octane/src/Commands/stubs/Caddyfile'));

    expect(fn (): mixed => (new GenerateSupervisorConfigStep('testing'))())
        ->toThrow(RuntimeException::class, 'laravel/octane');
});

it('parses FrankenPHP thread saturation from a real metrics payload', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // Requiring the generated emitter is safe — its loop only runs when the file is the
    // executed script, so this just defines the parser for a direct call.
    require_once Paths::build('docker/yolo-saturation.php');

    $payload = <<<'METRICS'
# HELP frankenphp_busy_threads Number of busy PHP threads
# TYPE frankenphp_busy_threads gauge
frankenphp_busy_threads 6
# HELP frankenphp_total_threads Total number of PHP threads
# TYPE frankenphp_total_threads gauge
frankenphp_total_threads 8
METRICS;

    // 6 of 8 threads busy = 75%. The HELP/TYPE comment lines for the same metric names
    // must not be mistaken for the gauge lines.
    expect(yolo_parse_saturation($payload))->toBe(75.0);

    // No gauges (metrics off) reads as null, so the emitter stays silent rather than
    // emitting a bogus datapoint.
    expect(yolo_parse_saturation("frankenphp_other 1\n"))->toBeNull();
});

it('does not double-inject metrics when the Octane stub already enables them', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);

    // A future Octane stub that already turns metrics on.
    file_put_contents(Paths::base('vendor/laravel/octane/src/Commands/stubs/Caddyfile'), <<<'STUB'
{
	metrics
	{$CADDY_GLOBAL_OPTIONS}
}

{$CADDY_SERVER_SERVER_NAME} {
	route {
		php_server
	}
}
STUB);

    (new GenerateSupervisorConfigStep('testing'))();

    // Exactly one metrics directive — the existing one, not a second injected copy.
    expect(preg_match_all('/^\s*metrics\s*$/m', file_get_contents(Paths::build('docker/Caddyfile'))))->toBe(1);
});

it('hard-fails when the Octane stub has no global options block to enable metrics in', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);

    // A stub with no leading global-options block (only a site block) — there's nowhere
    // safe to add the metrics option, so the build refuses rather than ship it dark.
    file_put_contents(Paths::base('vendor/laravel/octane/src/Commands/stubs/Caddyfile'), <<<'STUB'
{$CADDY_SERVER_SERVER_NAME} {
	route {
		php_server
	}
}
STUB);

    expect(fn (): mixed => (new GenerateSupervisorConfigStep('testing'))())
        ->toThrow(RuntimeException::class, 'global options block');
});
