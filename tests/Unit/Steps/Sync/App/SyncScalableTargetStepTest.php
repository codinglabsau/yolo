<?php

use Aws\Result;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Steps\Sync\App\SyncScalableTargetStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 2, 'max' => 6]]],
    ]);
});

it('records the pending registration on a greenfield plan pass instead of skipping', function (): void {
    // Nothing exists yet — the plan pass runs before any sibling is created. A
    // bare SKIPPED here prunes the step from the apply pass, leaving a fresh app
    // without autoscaling until a second sync (the two-pass contract's disguised
    // form: a skip condition reading an uncreated sibling). No ECS client is
    // bound at all: the step must not gate on the live service.
    $aa = [];
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => []])], $aa);

    $step = new SyncScalableTargetStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and($step->changes())->toHaveCount(2)
        ->and(collect($aa)->pluck('name'))->not->toContain('RegisterScalableTarget');
});

it('would-create the target on a dry-run without registering', function (): void {
    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => []])], $aa);

    expect((new SyncScalableTargetStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(collect($aa)->pluck('name'))->not->toContain('RegisterScalableTarget');
});

it('creates the target when applying and the service exists', function (): void {
    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => []]),
        'RegisterScalableTarget' => new Result([]),
    ], $aa);

    expect((new SyncScalableTargetStep())([]))->toBe(StepResult::CREATED);
    expect(collect($aa)->pluck('name'))->toContain('RegisterScalableTarget');
});

it('refuses to reduce live bounds under --force', function (): void {
    Prompt::setOutput(new BufferedOutput());

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    // Live is 5–10; manifest (from beforeEach) is 2–6 → reconciling would lower both.
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 5, 'MaxCapacity' => 10]]])], $aa);

    expect((new SyncScalableTargetStep())(['force' => true]))->toBe(StepResult::SKIPPED);
    expect(collect($aa)->pluck('name'))->not->toContain('RegisterScalableTarget');
});

it('reduces live bounds when run attended (no force)', function (): void {
    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 5, 'MaxCapacity' => 10]]]),
        'RegisterScalableTarget' => new Result([]),
    ], $aa);

    expect((new SyncScalableTargetStep())([]))->toBe(StepResult::SYNCED);
    expect(collect($aa)->firstWhere('name', 'RegisterScalableTarget')['args'])->toMatchArray(['MinCapacity' => 2, 'MaxCapacity' => 6]);
});

it('deregisters the target when the autoscaling block is removed', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => false]],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 1, 'MaxCapacity' => 4]]]),
        'DeregisterScalableTarget' => new Result([]),
    ], $aa);

    expect((new SyncScalableTargetStep())([]))->toBe(StepResult::DELETED);
    expect(collect($aa)->pluck('name'))->toContain('DeregisterScalableTarget');
});

it('would-deregister on a dry-run without deregistering', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => false]],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 1, 'MaxCapacity' => 4]]]),
        'DeregisterScalableTarget' => new Result([]),
    ], $aa);

    expect((new SyncScalableTargetStep())(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE);
    expect(collect($aa)->pluck('name'))->not->toContain('DeregisterScalableTarget');
});

it('skips when autoscaling is removed and no target is registered', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => false]],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => []])], $aa);

    expect((new SyncScalableTargetStep())([]))->toBe(StepResult::SKIPPED);
    expect(collect($aa)->pluck('name'))->not->toContain('DeregisterScalableTarget');
});
