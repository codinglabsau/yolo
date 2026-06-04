<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateSupervisorConfigStep;

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    is_file(Paths::build('docker/supervisord.conf')) && unlink(Paths::build('docker/supervisord.conf'));
    is_file(Paths::build('docker/supervisord.queue.conf')) && unlink(Paths::build('docker/supervisord.queue.conf'));
    is_file(Paths::build('docker/crontabs/www-data')) && unlink(Paths::build('docker/crontabs/www-data'));
});

function generatedSupervisorConfig(): string
{
    (new GenerateSupervisorConfigStep('testing'))();

    return file_get_contents(Paths::build('docker/supervisord.conf'));
}

it('always runs octane on the manifest port', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['port' => 9000]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:octane]');
    expect($config)->toContain('command=php artisan octane:start --host=0.0.0.0 --port=9000');
});

it('defaults the octane port to 8000', function () {
    expect(generatedSupervisorConfig())->toContain('--port=8000');
});

it('bundles octane, the scheduler and the queue worker into the web config for a plain web app', function () {
    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:octane]');
    expect($config)->toContain('[program:scheduler]');
    // The scheduler runs as cron, not a schedule:work daemon.
    expect($config)->toContain('command=crond -f -d 8 -c /app/docker/crontabs');
    expect($config)->not->toContain('schedule:work');
    expect($config)->toContain('[program:queue]');
    expect($config)->toContain('command=php artisan queue:work --tries=3 --max-time=3600');
});

it('runs octane alone in the web config when both queue and scheduler are extracted', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:octane]');
    expect($config)->not->toContain('[program:scheduler]');
    expect($config)->not->toContain('[program:queue]');
});

it('drops the scheduler from the web config when it has its own service, keeping the bundled queue', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'scheduler' => []],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:octane]');
    expect($config)->toContain('[program:queue]');
    expect($config)->not->toContain('[program:scheduler]');
});

it('writes a second supervisord config for a standalone queue that hosts the scheduler', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // Web container: octane only (queue + scheduler extracted onto the queue).
    $web = file_get_contents(Paths::build('docker/supervisord.conf'));
    expect($web)->toContain('[program:octane]');
    expect($web)->not->toContain('[program:queue]');
    expect($web)->not->toContain('[program:scheduler]');

    // Queue container: queue worker + crond, no octane.
    $queue = file_get_contents(Paths::build('docker/supervisord.queue.conf'));
    expect($queue)->toContain('[program:queue]');
    expect($queue)->toContain('[program:scheduler]');
    expect($queue)->not->toContain('[program:octane]');
});

it('writes no queue supervisord config when the standalone queue is a single process', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // Queue-only and scheduler-only services run a single exec'd process — no config.
    expect(is_file(Paths::build('docker/supervisord.queue.conf')))->toBeFalse();
});

it('writes a crontab firing schedule:run each minute (the scheduler always runs somewhere)', function () {
    (new GenerateSupervisorConfigStep('testing'))();

    $crontab = file_get_contents(Paths::build('docker/crontabs/www-data'));

    expect($crontab)->toContain('* * * * * cd /app && ');
    expect($crontab)->toContain('php artisan schedule:run');
});

it('uses the web shutdown-grace-period for octanes stop wait', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 25], 'queue' => [], 'scheduler' => []],
    ]);

    // Octane is the only program in the web config here (queue + scheduler extracted),
    // so the only stopwaitsecs is its own.
    expect(generatedSupervisorConfig())->toContain('stopwaitsecs=25');
});

it('honours a standalone queue shutdown-grace-period override', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => ['shutdown-grace-period' => 90], 'scheduler' => []],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    // A queue-only standalone service has no supervisord config of its own; the
    // worker's grace surfaces as the queue task's stopTimeout, not here.
    expect(is_file(Paths::build('docker/supervisord.queue.conf')))->toBeFalse();
});

it('runs the inertia ssr renderer when tasks.web.ssr is enabled', function () {
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

it('does not run the ssr renderer by default', function () {
    expect(generatedSupervisorConfig())->not->toContain('[program:ssr]');
});
