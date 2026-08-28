<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\GeneratePhpIniStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    if (is_file(Paths::build('docker/php.ini'))) {
        unlink(Paths::build('docker/php.ini'));
    }
});

it('bakes the baseline php.ini into the build context', function (): void {
    $step = new GeneratePhpIniStep('testing');

    expect($step())->toBe(StepResult::SUCCESS)
        ->and(file_get_contents(Paths::build('docker/php.ini')))
        ->toBe(file_get_contents(Paths::stubs('php.ini.stub')));
});

it('leaves a published php.ini untouched', function (): void {
    // The app's copy arrived with CopyApplicationStep — publishing is how an
    // app takes ownership of the values, so the baseline must not clobber it.
    $path = Paths::build('docker/php.ini');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, "upload_max_filesize = 512M\n");

    $step = new GeneratePhpIniStep('testing');

    expect($step())->toBe(StepResult::SKIPPED)
        ->and(file_get_contents($path))->toBe("upload_max_filesize = 512M\n");
});

it('ships a baseline that lifts the compile-default request-body limits', function (): void {
    // The whole point of the baseline: PHP's bare defaults cap uploads at 2M
    // and POST bodies at 8M, and nothing else in the stack imposes a limit.
    $stub = file_get_contents(Paths::stubs('php.ini.stub'));

    expect($stub)->toContain('upload_max_filesize = 10M')
        ->and($stub)->toContain('post_max_size = 12M')
        ->and($stub)->toContain('opcache.validate_timestamps = 0');
});
