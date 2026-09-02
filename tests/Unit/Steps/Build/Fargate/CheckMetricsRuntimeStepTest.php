<?php

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckMetricsRuntimeStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);
});

it('skips without probing the image when the web tier is not autoscaling', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => false]],
    ]);

    // A probe that throws proves the step short-circuits before running the image.
    $step = new CheckMetricsRuntimeStep('testing', probe: function (): void {
        throw new RuntimeException('probe should not run when burst is off');
    });

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SKIPPED);
});

it('probes the image in classic mode too, where the thread gauges are the burst signal', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false, 'autoscaling' => true]],
    ]);

    $probed = false;
    $step = new CheckMetricsRuntimeStep('testing', probe: function () use (&$probed): true {
        $probed = true;

        return true;
    });

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SUCCESS);
    expect($probed)->toBeTrue();
});

it('passes when the built image ships a metrics Caddyfile', function (): void {
    $step = new CheckMetricsRuntimeStep('testing', probe: fn (): true => true);

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SUCCESS);
});

it('hard-fails when the built image has no metrics Caddyfile', function (): void {
    $step = new CheckMetricsRuntimeStep('testing', probe: fn (): false => false);

    expect(fn (): StepResult => $step(['app-version' => '26.24.1.1200']))
        ->toThrow(RuntimeException::class, 'no Caddyfile with');
});

it('probes the built image tag', function (): void {
    $image = null;
    $step = new CheckMetricsRuntimeStep('testing', probe: function (string $tag) use (&$image): true {
        $image = $tag;

        return true;
    });

    $step(['app-version' => '26.24.1.1200']);

    expect($image)->toEndWith('/yolo-testing-my-app:26.24.1.1200');
});

it('builds a docker probe that greps the baked Caddyfile for the metrics directive', function (): void {
    expect(CheckMetricsRuntimeStep::command('repo:26.24.1.1200'))->toBe([
        'docker', 'run', '--rm', '--entrypoint', 'sh', 'repo:26.24.1.1200', '-c',
        'grep -qE "^[[:space:]]*metrics[[:space:]]*$" /app/docker/Caddyfile',
    ]);
});
