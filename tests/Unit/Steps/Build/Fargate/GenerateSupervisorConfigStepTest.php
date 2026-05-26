<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateSupervisorConfigStep;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => []],
    ]);

    is_dir(Paths::build('docker')) && array_map('unlink', glob(Paths::build('docker/*')));
});

function generatedSupervisorConfig(): string
{
    (new GenerateSupervisorConfigStep('testing'))();

    return file_get_contents(Paths::build('docker/supervisord.conf'));
}

it('always runs octane on the manifest port', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['port' => 9000]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:octane]');
    expect($config)->toContain('command=php artisan octane:frankenphp --host=0.0.0.0 --port=9000');
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
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['queue' => true, 'scheduler' => true]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:scheduler]');
    expect($config)->toContain('command=php artisan schedule:work');
    expect($config)->toContain('[program:queue]');
    expect($config)->toContain('command=php artisan queue:work --tries=3 --max-time=3600');
});

it('uses the web stop-grace for octanes stop wait', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['stop-grace' => 25]],
    ]);

    // Octane is the only program, so the only stopwaitsecs in the file is its own.
    expect(generatedSupervisorConfig())->toContain('stopwaitsecs=25');
});

it('honours a queue stop-grace override via the object form', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['queue' => ['stop-grace' => 90]]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:queue]');
    expect($config)->toContain('stopwaitsecs=90');
});

it('runs the queue worker without the scheduler when only queue is enabled', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['queue' => true]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:queue]');
    expect($config)->not->toContain('[program:scheduler]');
    expect($config)->toContain('[program:octane]');
});
