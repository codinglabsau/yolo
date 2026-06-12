<?php

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckSchedulerRuntimeStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);
});

it('passes when the built image has supercronic', function (): void {
    $step = new CheckSchedulerRuntimeStep('testing', probe: fn (): true => true);

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SUCCESS);
});

it('hard-fails when the built image has no supercronic', function (): void {
    // Every app hosts the scheduler somewhere (there's no opt-out), so the check
    // is unconditional — no manifest key turns it off.
    $step = new CheckSchedulerRuntimeStep('testing', probe: fn (): false => false);

    expect(fn (): StepResult => $step(['app-version' => '26.24.1.1200']))
        ->toThrow(RuntimeException::class, 'no supercronic binary');
});

it('probes the built image tag', function (): void {
    $image = null;
    $step = new CheckSchedulerRuntimeStep('testing', probe: function (string $tag) use (&$image): true {
        $image = $tag;

        return true;
    });

    $step(['app-version' => '26.24.1.1200']);

    expect($image)->toEndWith('/my-app:26.24.1.1200');
});

it('builds a docker probe that checks supercronic on the image PATH', function (): void {
    expect(CheckSchedulerRuntimeStep::command('repo:26.24.1.1200'))->toBe([
        'docker', 'run', '--rm', '--entrypoint', 'sh', 'repo:26.24.1.1200', '-c', 'command -v supercronic',
    ]);
});
