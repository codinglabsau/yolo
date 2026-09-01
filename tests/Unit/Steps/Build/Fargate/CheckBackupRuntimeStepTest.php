<?php

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckBackupRuntimeStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'backups' => true,
        'tasks' => ['web' => true],
    ]);
});

it('passes when the built image has mysqldump and zstd', function (): void {
    $step = new CheckBackupRuntimeStep('testing', probe: fn (): true => true);

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SUCCESS);
});

it('hard-fails when the built image is missing the backup tools', function (): void {
    // A missing binary would otherwise be silent: the deploy goes green and the
    // daily backup errors unnoticed until the day a restore is needed.
    $step = new CheckBackupRuntimeStep('testing', probe: fn (): false => false);

    expect(fn (): StepResult => $step(['app-version' => '26.24.1.1200']))
        ->toThrow(RuntimeException::class, 'missing mysqldump and/or zstd');
});

it('skips the probe by default — backups are an opt-in', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    $step = new CheckBackupRuntimeStep('testing', probe: fn (): false => false);

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SKIPPED);
});

it('skips the probe when cron runs nowhere to host the dump', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'backups' => true,
        'tasks' => ['web' => true, 'scheduler' => false],
    ]);

    $step = new CheckBackupRuntimeStep('testing', probe: fn (): false => false);

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SKIPPED);
});

it('probes the built image tag', function (): void {
    $image = null;
    $step = new CheckBackupRuntimeStep('testing', probe: function (string $tag) use (&$image): true {
        $image = $tag;

        return true;
    });

    $step(['app-version' => '26.24.1.1200']);

    expect($image)->toEndWith('/yolo-testing-my-app:26.24.1.1200');
});

it('builds a docker probe that checks both binaries on the image PATH', function (): void {
    expect(CheckBackupRuntimeStep::command('repo:26.24.1.1200'))->toBe([
        'docker', 'run', '--rm', '--entrypoint', 'sh', 'repo:26.24.1.1200', '-c', 'command -v mysqldump && command -v zstd',
    ]);
});
