<?php

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckSsrRuntimeStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true]],
    ]);
});

it('skips without probing the image when ssr is off', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    // A probe that throws proves the step short-circuits before running the image.
    $step = new CheckSsrRuntimeStep('testing', probe: function (): void {
        throw new RuntimeException('probe should not run when ssr is off');
    });

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SKIPPED);
});

it('passes when the built image has a node runtime', function (): void {
    $step = new CheckSsrRuntimeStep('testing', probe: fn (): true => true);

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SUCCESS);
});

it('hard-fails when the built image has no node runtime', function (): void {
    $step = new CheckSsrRuntimeStep('testing', probe: fn (): false => false);

    expect(fn (): StepResult => $step(['app-version' => '26.24.1.1200']))
        ->toThrow(RuntimeException::class, 'no Node runtime');
});

it('probes the built image tag', function (): void {
    $image = null;
    $step = new CheckSsrRuntimeStep('testing', probe: function (string $tag) use (&$image): true {
        $image = $tag;

        return true;
    });

    $step(['app-version' => '26.24.1.1200']);

    expect($image)->toEndWith('/my-app:26.24.1.1200');
});

it('builds a docker probe that checks node on the image PATH', function (): void {
    expect(CheckSsrRuntimeStep::command('repo:26.24.1.1200'))->toBe([
        'docker', 'run', '--rm', '--entrypoint', 'sh', 'repo:26.24.1.1200', '-c', 'command -v node',
    ]);
});
