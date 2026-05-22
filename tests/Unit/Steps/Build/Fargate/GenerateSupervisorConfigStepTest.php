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

it('runs the scheduler and queue worker by default (the web task does everything)', function () {
    $config = generatedSupervisorConfig();

    expect($config)->toContain('[program:scheduler]');
    expect($config)->toContain('command=php artisan schedule:work');
    expect($config)->toContain('[program:queue]');
    expect($config)->toContain('command=php artisan queue:work --tries=3 --max-time=3600');
});

it('omits the queue worker when tasks.web.queue is false', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['queue' => false]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->not->toContain('[program:queue]');
    expect($config)->toContain('[program:octane]');
    expect($config)->toContain('[program:scheduler]');
});

it('omits the scheduler when tasks.web.scheduler is false', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['scheduler' => false]],
    ]);

    $config = generatedSupervisorConfig();

    expect($config)->not->toContain('[program:scheduler]');
    expect($config)->toContain('[program:octane]');
    expect($config)->toContain('[program:queue]');
});
