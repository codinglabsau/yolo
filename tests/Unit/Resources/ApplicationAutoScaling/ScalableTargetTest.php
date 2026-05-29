<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['min' => 2, 'max' => 6]]],
    ]);
});

it('builds the ECS service resource id', function () {
    expect(ScalableTarget::resourceId())->toBe('service/yolo-testing-my-app/yolo-testing-my-app-web');
});

it('reports the target absent when none is registered', function () {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => []]),
    ], $captured);

    expect((new ScalableTarget())->exists())->toBeFalse();
    expect((new ScalableTarget())->current())->toBeNull();
});

it('registers the target with the manifest min/max when absent', function () {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => []]),
        'RegisterScalableTarget' => new Result([]),
    ], $captured);

    $changes = (new ScalableTarget())->synchronise(apply: true);

    expect($changes)->toHaveCount(2);

    $register = collect($captured)->firstWhere('name', 'RegisterScalableTarget');

    expect($register['args'])->toMatchArray([
        'ServiceNamespace' => 'ecs',
        'ResourceId' => 'service/yolo-testing-my-app/yolo-testing-my-app-web',
        'ScalableDimension' => 'ecs:service:DesiredCount',
        'MinCapacity' => 2,
        'MaxCapacity' => 6,
    ]);
});

it('does not register when the live min/max already match', function () {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [
            ['MinCapacity' => 2, 'MaxCapacity' => 6],
        ]]),
    ], $captured);

    $changes = (new ScalableTarget())->synchronise(apply: true);

    expect($changes)->toBe([]);
    expect(collect($captured)->pluck('name'))->not->toContain('RegisterScalableTarget');
});

it('reports drift but does not register on a dry-run', function () {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [
            ['MinCapacity' => 1, 'MaxCapacity' => 6],
        ]]),
    ], $captured);

    $changes = (new ScalableTarget())->synchronise(apply: false);

    expect($changes)->toHaveCount(1);
    expect($changes[0]->describe())->toContain('MinCapacity');
    expect(collect($captured)->pluck('name'))->not->toContain('RegisterScalableTarget');
});
