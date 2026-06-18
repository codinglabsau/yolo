<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateSupervisorConfigStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        // The process-tree baseline is a fixed web tier; the autoscaling cases
        // (saturation emitter, metrics Caddyfile) opt in explicitly below.
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
    // Non-autoscaling so the web command is the bare octane:start (no emitter → no
    // request-path nice, no --caddyfile); the autoscaling forms are covered below.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['port' => 9000, 'autoscaling' => false]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:web]');
    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=9000');
});

it('defaults the octane port to 8000', function (): void {
    expect(generatedSupervisorConfig())->toContain('--port=8000');
});

it('runs frankenphp classic mode on the manifest port when tasks.web.octane is false', function (): void {
    // Non-autoscaling so the web command is the bare classic launcher (no emitter → no
    // request-path nice); the niced autoscaling form is covered below.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false, 'port' => 9000, 'autoscaling' => false]],
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
    // The scheduler runs as cron, not a schedule:work daemon. Bundled alongside the
    // web server, it's niced so the request path wins CPU under contention (see the
    // co-location tests below for the full nice contract).
    expect($config)->toContain('command=nice -n 19 supercronic /app/docker/crontab');
    expect($config)->not->toContain('schedule:work');
    expect($config)->toContain('[program:queue]');
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
});

it('nices the bundled queue and scheduler so the web request path wins CPU under contention', function (): void {
    // Plain web app: web + queue + scheduler share one container, so the background
    // programs launch under nice while the web server keeps full CPU priority.
    $config = generatedSupervisorConfig();

    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 19 supercronic /app/docker/crontab');

    // The web server is never niced — it's the request path the nicing protects.
    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000');
    expect($config)->not->toContain('nice -n 19 php artisan octane:start');
});

it('nices the bundled background programs in classic mode too', function (): void {
    // The web program is the request path whether it's Octane or FrankenPHP classic
    // mode — the co-located background work yields to it either way. Non-autoscaling
    // keeps it a clean two-tier (web at full priority, background niced).
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false, 'autoscaling' => false]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('command=frankenphp php-server');
    expect($config)->not->toContain('nice -n 19 frankenphp');
    expect($config)->not->toContain('nice -n 3 frankenphp');
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 19 supercronic /app/docker/crontab');
});

it('keeps the saturation emitter at the highest priority so burst metrics report promptly', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);

    $config = generatedSupervisorConfig();

    // The emitter must always be schedulable to publish saturation, so it alone stays
    // at nice 0 — every other program in the group is niced below it.
    expect($config)->toContain('command=php /app/docker/yolo-saturation.php');
    expect($config)->not->toContain('nice -n 19 php /app/docker/yolo-saturation.php');
    expect($config)->not->toContain('nice -n 3 php /app/docker/yolo-saturation.php');

    // The request path is niced just under the emitter; the background tier well below it.
    expect($config)->toContain('command=nice -n 3 php artisan octane:start');
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 19 supercronic /app/docker/crontab');
});

it('orders the whole web group emitter > web/ssr > queue/scheduler when burst is bundled', function (): void {
    // Grouped topology: web + ssr + queue + scheduler + the burst emitter all share
    // the one web container, so all three priority tiers appear at once.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true, 'autoscaling' => true]],
    ]);

    $config = generatedSupervisorConfig();

    // Tier 0 — the emitter, never niced.
    expect($config)->toContain('command=php /app/docker/yolo-saturation.php');
    // Tier 1 — the request path (web + ssr), niced just below the emitter.
    expect($config)->toContain('command=nice -n 3 php artisan octane:start');
    expect($config)->toContain('command=nice -n 3 php artisan inertia:start-ssr');
    // Tier 2 — background work, well below the request path.
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 19 supercronic /app/docker/crontab');
});

it('lifts the emitter above web and ssr in a separated-web container, leaving the standalone queue untouched', function (): void {
    // Separated-web topology: the worker tier is extracted, so the web container holds
    // only web + ssr + the emitter — but the emitter must still outrank the request path
    // (its CPU contenders here are Octane and SSR, neither of which can be deprioritised
    // as background).
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true, 'autoscaling' => true], 'queue' => true],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    $web = file_get_contents(Paths::build('docker/supervisord.conf'));
    expect($web)->toContain('command=php /app/docker/yolo-saturation.php');
    expect($web)->toContain('command=nice -n 3 php artisan octane:start');
    expect($web)->toContain('command=nice -n 3 php artisan inertia:start-ssr');
    expect($web)->not->toContain('[program:queue]');
    expect($web)->not->toContain('[program:scheduler]');

    // The standalone queue+scheduler service never carries the emitter, so web
    // autoscaling must not leak any nice into it.
    $queue = file_get_contents(Paths::build('docker/supervisord.queue.conf'));
    expect($queue)->toContain('command=php artisan queue:work --tries=3 --max-time=3600');
    expect($queue)->toContain('command=supercronic /app/docker/crontab');
    expect($queue)->not->toContain('nice');
});

it('keeps web and ssr at full priority when no burst emitter shares the container', function (): void {
    // Non-autoscaling: no emitter, so there's nothing to lift the request path under —
    // web + ssr stay at the default priority (and ssr is never treated as background),
    // only the queue/scheduler tier is niced.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true, 'autoscaling' => false]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=8000');
    expect($config)->toContain('command=php artisan inertia:start-ssr');
    expect($config)->not->toContain('nice -n 3');
    expect($config)->not->toContain('nice -n 19 php artisan inertia:start-ssr');
    expect($config)->toContain('command=nice -n 19 php artisan queue:work --tries=3 --max-time=3600');
    expect($config)->toContain('command=nice -n 19 supercronic /app/docker/crontab');
});

it('nices nothing in a web-only container when the worker tier is extracted and burst is off', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => false], 'queue' => true, 'scheduler' => true],
    ]);

    // Octane alone — no co-located background work to arbitrate and no emitter to outrank,
    // so nothing is niced. (Autoscaling bundles the emitter and lifts web above it — see
    // the separated-web ordering test above.)
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
        ->toContain('$floor = 70;')
        ->toContain('$threshold = 80;')
        ->toContain("'region' => 'ap-southeast-2'")
        ->toContain('->putMetricData(')
        ->toContain("'Namespace' => 'YOLO/Autoscaling'")
        ->toContain("'MetricName' => 'WorkerSaturation'")
        ->toContain("['Name' => 'ServiceName', 'Value' => \$service]")
        ->toContain('frankenphp_busy_workers')
        ->not->toContain('_aws')
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

    // Autoscaling bundles the burst emitter, so the web command is niced just below it.
    expect(generatedSupervisorConfig())
        ->toContain('command=nice -n 3 php artisan octane:start --host=0.0.0.0 --port=8000 --caddyfile=/app/docker/Caddyfile');
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
    // Autoscaling still bundles the emitter, so the web command is niced below it like
    // any request-path program.
    expect($config)->toContain('command=nice -n 3 frankenphp php-server');
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

it('parses FrankenPHP worker saturation from a real metrics payload', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // Requiring the generated emitter is safe — its loop only runs when the file is the
    // executed script, so this just defines the parser for a direct call.
    require_once Paths::build('docker/yolo-saturation.php');

    $payload = <<<'METRICS'
# HELP frankenphp_busy_workers Number of busy workers
# TYPE frankenphp_busy_workers gauge
frankenphp_busy_workers 6
# HELP frankenphp_total_workers Total number of workers
# TYPE frankenphp_total_workers gauge
frankenphp_total_workers 8
METRICS;

    // 6 of 8 workers busy = 75%. The HELP/TYPE comment lines for the same metric names
    // must not be mistaken for the gauge lines.
    expect(yolo_parse_saturation($payload))->toBe(75.0);

    // No gauges (metrics off) reads as null, so the emitter stays silent rather than
    // publishing a bogus datapoint.
    expect(yolo_parse_saturation("frankenphp_other 1\n"))->toBeNull();

    // FrankenPHP labels the gauge per worker script in production
    // (`frankenphp_busy_workers{worker="/app/..."}`); the optional label set must parse.
    expect(yolo_parse_saturation("frankenphp_busy_workers{worker=\"/app\"} 2\nfrankenphp_total_workers{worker=\"/app\"} 4\n"))->toBe(50.0);

    // Multiple worker scripts each emit their own labelled line; the pool is the sum, so
    // 3+1 busy of 4+4 total reads 50%, not just the first line's 3/4.
    expect(yolo_parse_saturation(
        "frankenphp_busy_workers{worker=\"a\"} 3\nfrankenphp_busy_workers{worker=\"b\"} 1\n" .
        "frankenphp_total_workers{worker=\"a\"} 4\nfrankenphp_total_workers{worker=\"b\"} 4\n"
    ))->toBe(50.0);

    // Idle reads a clean 0.0 (not null), so the emit floor — not a parse gap — is what
    // keeps the emitter silent at rest.
    expect(yolo_parse_saturation("frankenphp_busy_workers 0\nfrankenphp_total_workers 4\n"))->toBe(0.0);

    // A mid-reload scrape can catch more busy workers than total (the gauges read at
    // different instants), producing an impossible >100% ratio that would false-fire the
    // burst alarm. It is dropped to null, not published, so the emitter stays silent.
    expect(yolo_parse_saturation("frankenphp_busy_workers 15\nfrankenphp_total_workers 4\n"))->toBeNull();

    // A zero-worker pool (nothing live yet) is equally bogus — null, never a divide blow-up.
    expect(yolo_parse_saturation("frankenphp_busy_workers 0\nfrankenphp_total_workers 0\n"))->toBeNull();
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
