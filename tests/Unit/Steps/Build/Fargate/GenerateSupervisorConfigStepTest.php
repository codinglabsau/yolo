<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateSupervisorConfigStep;

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    is_file(Paths::build('docker/supervisord.conf')) && unlink(Paths::build('docker/supervisord.conf'));
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

it('runs octane only by default — scheduler and queue are opt-in', function () {
    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:octane]');
    expect($config)->not->toContain('[program:scheduler]');
    expect($config)->not->toContain('[program:queue]');
});

it('runs the scheduler and queue worker when explicitly enabled', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => true, 'scheduler' => true]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:scheduler]');
    // The scheduler runs as cron, not a schedule:work daemon.
    expect($config)->toContain('command=crond -f -d 8 -c /app/docker/crontabs');
    expect($config)->not->toContain('schedule:work');
    expect($config)->toContain('[program:queue]');
    expect($config)->toContain('command=php artisan queue:work --tries=3 --max-time=3600');
});

it('writes a crontab firing schedule:run each minute when the scheduler is enabled', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['scheduler' => true]],
    ]);

    (new GenerateSupervisorConfigStep('testing'))();

    $crontab = file_get_contents(Paths::build('docker/crontabs/www-data'));

    expect($crontab)->toContain('* * * * * cd /app && ');
    expect($crontab)->toContain('php artisan schedule:run');
});

it('writes no crontab when the scheduler is disabled', function () {
    generatedSupervisorConfig();

    expect(is_file(Paths::build('docker/crontabs/www-data')))->toBeFalse();
});

it('uses the web shutdown-grace-period for octanes stop wait', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 25]],
    ]);

    // Octane is the only program, so the only stopwaitsecs in the file is its own.
    expect(generatedSupervisorConfig())->toContain('stopwaitsecs=25');
});

it('honours a queue shutdown-grace-period override via the object form', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => ['shutdown-grace-period' => 90]]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:queue]');
    expect($config)->toContain('stopwaitsecs=90');
});

it('runs the queue worker without the scheduler when only queue is enabled', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => true]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:queue]');
    expect($config)->not->toContain('[program:scheduler]');
    expect($config)->toContain('[program:octane]');
});
