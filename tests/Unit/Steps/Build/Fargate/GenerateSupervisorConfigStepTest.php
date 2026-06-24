<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateSupervisorConfigStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        // The process-tree baseline is a fixed web tier; the autoscaling case
        // (metrics Caddyfile) opts in explicitly below.
        'tasks' => ['web' => ['autoscaling' => false]],
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

it('runs octane on the hardcoded 8000 port by default', function (): void {
    // Non-autoscaling so the web command is the bare octane:start (no emitter → no
    // request-path nice, no --caddyfile); the autoscaling forms are covered below.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => false]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:web]');
    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000');
});

it('pins the octane worker pool from the task vCPU rather than FrankenPHP auto-detect (default 0.5 vCPU → 8)', function (): void {
    // FrankenPHP would auto-detect ~4 workers off the Fargate microVM's fixed ~2 vCPUs;
    // YOLO pins the count from the task's real allocation instead (16 × 0.5 vCPU = 8).
    expect(generatedSupervisorConfig())->toContain('--workers=8');
});

it('scales the pinned worker pool with the task vCPU allocation', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['cpu' => 1024, 'memory' => 2048, 'autoscaling' => false]],
    ]);

    expect(generatedSupervisorConfig())
        ->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000 --workers=16');
});

it('pins --workers after --caddyfile on an autoscaling octane tier', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);

    expect(generatedSupervisorConfig())
        ->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000 --caddyfile=/app/docker/Caddyfile --workers=8');
});

it('passes no --workers in classic mode (frankenphp php-server takes none)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false, 'autoscaling' => false]],
    ]);

    expect(generatedSupervisorConfig())->not->toContain('--workers');
});

it('runs frankenphp classic mode on the hardcoded 8000 port when tasks.web.octane is false', function (): void {
    // Non-autoscaling so the web command is the bare classic launcher (no emitter → no
    // request-path nice); the niced autoscaling form is covered below.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false, 'autoscaling' => false]],
    ]);

    $config = generatedSupervisorConfig();

    // Same web program slot, classic-mode command — no octane:start, no worker boot.
    expect($config)->toContain('[program:web]');
    expect($config)->toContain('command=frankenphp php-server --listen 0.0.0.0:8000 --root public/');
    expect($config)->not->toContain('octane:start');
});

it('bundles octane, the scheduler and the queue worker into the web config for a plain web app', function (): void {
    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:web]');
    expect($config)->toContain('[program:scheduler]');
    // The scheduler runs as cron, not a schedule:work daemon. Bundled alongside the
    // web server, it's niced so the request path wins CPU under contention (see the
    // co-location tests below for the full nice contract).
    expect($config)->toContain('command=nice -n 10 supercronic /app/docker/crontab');
    expect($config)->not->toContain('schedule:work');
    expect($config)->toContain('[program:queue]');
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
});

it('nices the bundled queue and scheduler so the web request path wins CPU under contention', function (): void {
    // Plain web app: web + queue + scheduler share one container, so the background
    // programs launch under nice while the web server keeps full CPU priority.
    $config = generatedSupervisorConfig();

    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 10 supercronic /app/docker/crontab');

    // The web server is never niced — it's the request path the nicing protects.
    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000');
    expect($config)->not->toContain('nice -n 19 php artisan octane:start');
});

it('nices the bundled background programs in classic mode too', function (): void {
    // The web program is the request path whether it's Octane or FrankenPHP classic
    // mode — the co-located background work yields to it either way (web at full
    // priority, the scheduler above the queue).
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false, 'autoscaling' => false]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('command=frankenphp php-server');
    expect($config)->not->toContain('nice -n 19 frankenphp');
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 10 supercronic /app/docker/crontab');
});

it('nices only the background tier for an autoscaling web app and bundles no saturation program', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);

    $config = generatedSupervisorConfig();

    // Burst worker-saturation metrics are published by a terminable middleware inside
    // the request (the YoloServiceProvider), not a supervised process — so there is no
    // saturation program in the tree.
    expect($config)->not->toContain('[program:saturation]');
    expect($config)->not->toContain('yolo-saturation');

    // Web stays at the default priority; only the background tier is niced down.
    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000 --caddyfile=/app/docker/Caddyfile');
    expect($config)->not->toContain('nice -n 19 php artisan octane:start');
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 10 supercronic /app/docker/crontab');
});

it('keeps web ≈ ssr at the default priority over the niced background tier when burst and ssr are bundled', function (): void {
    // Grouped topology: web + ssr + queue + scheduler share the one web container, so
    // both priority tiers appear at once.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true, 'autoscaling' => true]],
    ]);

    $config = generatedSupervisorConfig();

    // Tier 0 — web and ssr at the default priority, neither niced. No saturation program.
    expect($config)->not->toContain('[program:saturation]');
    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000 --caddyfile=/app/docker/Caddyfile');
    expect($config)->toContain('command=php artisan inertia:start-ssr');
    expect($config)->not->toContain('nice -n 19 php artisan octane:start');
    expect($config)->not->toContain('nice -n 19 php artisan inertia:start-ssr');
    // Tier 1 — background work, niced down so it can't starve the request path.
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 10 supercronic /app/docker/crontab');
});

it('runs web and ssr at the default priority in a separated-web container, leaving the standalone queue untouched', function (): void {
    // Separated-web topology: the worker tier is extracted, so the web container holds
    // only web + ssr — and with no background tier to nice, nothing is niced at all.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true, 'autoscaling' => true], 'queue' => true],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    $web = file_get_contents(Paths::build('docker/supervisord.conf'));
    expect($web)->not->toContain('[program:saturation]');
    expect($web)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000 --caddyfile=/app/docker/Caddyfile');
    expect($web)->toContain('command=php artisan inertia:start-ssr');
    expect($web)->not->toContain('nice');
    expect($web)->not->toContain('[program:queue]');
    expect($web)->not->toContain('[program:scheduler]');

    // Web autoscaling must not leak any nice into the standalone queue+scheduler service.
    $queue = file_get_contents(Paths::build('docker/supervisord.queue.conf'));
    expect($queue)->toContain('command=php artisan queue:work --tries=3 --max-time=3600');
    expect($queue)->toContain('command=supercronic /app/docker/crontab');
    expect($queue)->not->toContain('nice');
});

it('keeps web and ssr at the default priority while nicing the bundled background tier', function (): void {
    // Non-autoscaling: no emitter. web + ssr stay at the default priority (ssr is never
    // treated as background), and only the queue/scheduler tier is niced.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true, 'autoscaling' => false]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000');
    expect($config)->toContain('command=php artisan inertia:start-ssr');
    expect($config)->not->toContain('nice -n 19 php artisan inertia:start-ssr');
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 10 supercronic /app/docker/crontab');
});

it('nices nothing in a web-only container when the worker tier is extracted and burst is off', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => false], 'queue' => true, 'scheduler' => true],
    ]);

    // Octane alone — no co-located background work to arbitrate, so nothing is niced.
    expect(generatedSupervisorConfig())->not->toContain('nice');
});

it('nices nothing in a standalone queue+scheduler container — no request path to protect', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // The standalone queue co-hosts the scheduler but no web server, so both background
    // programs run at normal priority — nicing two co-equal background programs would
    // just restore parity.
    $queue = file_get_contents(Paths::build('docker/supervisord.queue.conf'));
    expect($queue)->toContain('command=php artisan queue:work --tries=3 --max-time=3600');
    expect($queue)->toContain('command=supercronic /app/docker/crontab');
    expect($queue)->not->toContain('nice');
});

it('runs octane alone in the web config when both queue and scheduler are extracted', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:web]');
    expect($config)->not->toContain('[program:scheduler]');
    expect($config)->not->toContain('[program:queue]');
});

it('drops the scheduler from the web config when it has its own service, keeping the bundled queue', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'scheduler' => true],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:web]');
    expect($config)->toContain('[program:queue]');
    expect($config)->not->toContain('[program:scheduler]');
});

it('writes a second supervisord config for a standalone queue that hosts the scheduler', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
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
        'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // Queue-only and scheduler-only services run a single exec'd process — no config.
    expect(is_file(Paths::build('docker/supervisord.queue.conf')))->toBeFalse();
});

it('writes a crontab firing schedule:run each minute wherever the scheduler runs', function (): void {
    (new GenerateSupervisorConfigStep('testing'))();

    $crontab = file_get_contents(Paths::build('docker/crontab'));

    // The whole entry: supercronic jobs inherit the container env and have their
    // output captured, so no PATH override or fd redirect rides along.
    expect($crontab)->toContain("* * * * * cd /app && php artisan schedule:run\n");
});

it('writes no crontab when the scheduler is disabled', function (): void {
    // tasks.scheduler: false → cron runs nowhere, so the crontab the image would
    // never read is not generated.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'scheduler' => false],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    expect(is_file(Paths::build('docker/crontab')))->toBeFalse();
});

it('uses the web shutdown-grace-period for octanes stop wait', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 25], 'queue' => true, 'scheduler' => true],
    ]);

    // Octane is the only program in the web config here (queue + scheduler extracted),
    // so the only stopwaitsecs is its own.
    expect(generatedSupervisorConfig())->toContain('stopwaitsecs=25');
});

it('honours a standalone queue shutdown-grace-period override', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['shutdown-grace-period' => 90], 'scheduler' => true],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // A queue-only standalone service has no supervisord config of its own; the
    // worker's grace surfaces as the queue task's stopTimeout, not here.
    expect(is_file(Paths::build('docker/supervisord.queue.conf')))->toBeFalse();
});

it('runs the inertia ssr renderer when tasks.web.ssr is enabled', function (): void {
    // Non-autoscaling so the ssr command is bare (no emitter → no request-path nice); the
    // niced autoscaling form is covered by the web-group ordering tests above.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true, 'autoscaling' => false]],
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

it('never bundles a saturation program — burst metrics ride the request, not a supervised loop', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 2, 'max' => 8]]],
    ]);

    $config = generatedSupervisorConfig();

    // No supervised emitter in either the burst-off baseline or a burst-on app — the
    // YoloServiceProvider's terminable middleware publishes from inside the request.
    expect($config)->not->toContain('[program:saturation]');
    expect($config)->not->toContain('yolo-saturation');
    expect(is_file(Paths::build('docker/yolo-saturation.php')))->toBeFalse();
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

    // The web command runs against the metrics Caddyfile via --caddyfile and stays at
    // the default priority (never niced).
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
    // Autoscaling still bundles the emitter, but the web command stays at the default
    // priority like the emitter itself.
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
