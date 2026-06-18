<?php

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckSchedulerRuntimeStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
});

it('passes when the built image has supercronic', function (): void {
    $step = new CheckSchedulerRuntimeStep('testing', probe: fn (): true => true);

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SUCCESS);
});

it('hard-fails when the built image has no supercronic', function (): void {
    // The scheduler runs in the web container here (the default), so supercronic
    // is required — a missing binary fails the build.
    $step = new CheckSchedulerRuntimeStep('testing', probe: fn (): false => false);

    expect(fn (): StepResult => $step(['app-version' => '26.24.1.1200']))
        ->toThrow(RuntimeException::class, 'no supercronic binary');
});

it('skips the supercronic probe when the scheduler is disabled', function (): void {
    // tasks.scheduler: false → cron runs nowhere, so the image needn't carry
    // supercronic; the probe never runs (a failing probe would otherwise throw).
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'scheduler' => false],
    ]);

    $step = new CheckSchedulerRuntimeStep('testing', probe: fn (): false => false);

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SKIPPED);
});

it('probes the built image tag', function (): void {
    $image = null;
    $step = new CheckSchedulerRuntimeStep('testing', probe: function (string $tag) use (&$image): true {
        $image = $tag;

        return true;
    });

    $step(['app-version' => '26.24.1.1200']);

    expect($image)->toEndWith('/yolo-testing-my-app:26.24.1.1200');
});

it('builds a docker probe that checks supercronic on the image PATH', function (): void {
    expect(CheckSchedulerRuntimeStep::command('repo:26.24.1.1200'))->toBe([
        'docker', 'run', '--rm', '--entrypoint', 'sh', 'repo:26.24.1.1200', '-c', 'command -v supercronic',
    ]);
});
