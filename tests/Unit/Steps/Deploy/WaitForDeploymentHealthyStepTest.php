<?php

use Codinglabs\Yolo\Steps\Deploy\WaitForDeploymentHealthyStep;

function task(string $revision, string $ip): array
{
    return [
        'taskDefinitionArn' => $revision,
        'attachments' => [
            ['details' => [
                ['name' => 'subnetId', 'value' => 'subnet-1'],
                ['name' => 'privateIPv4Address', 'value' => $ip],
            ]],
        ],
    ];
}

function target(string $ip, string $state): array
{
    return ['Target' => ['Id' => $ip], 'TargetHealth' => ['State' => $state]];
}

const NEW_REV = 'arn:aws:ecs:ap-southeast-2:111:task-definition/yolo-production-codinglabs-web:9';
const OLD_REV = 'arn:aws:ecs:ap-southeast-2:111:task-definition/yolo-production-codinglabs-web:8';

it('is healthy when every new-revision task has a healthy target', function () {
    $healthy = WaitForDeploymentHealthyStep::newTasksAreHealthy(
        tasks: [task(NEW_REV, '10.0.0.5')],
        newRevision: NEW_REV,
        desiredCount: 1,
        targetHealth: [target('10.0.0.5', 'healthy')],
    );

    expect($healthy)->toBeTrue();
});

it('is not healthy while the new task is still in initial state', function () {
    $healthy = WaitForDeploymentHealthyStep::newTasksAreHealthy(
        tasks: [task(NEW_REV, '10.0.0.5')],
        newRevision: NEW_REV,
        desiredCount: 1,
        targetHealth: [target('10.0.0.5', 'initial')],
    );

    expect($healthy)->toBeFalse();
});

it('ignores the old draining task and the target it leaves healthy', function () {
    // Old task still healthy, new task not yet healthy → must not report healthy
    // (this is the trap with counting total healthy targets).
    $healthy = WaitForDeploymentHealthyStep::newTasksAreHealthy(
        tasks: [task(OLD_REV, '10.0.0.4'), task(NEW_REV, '10.0.0.5')],
        newRevision: NEW_REV,
        desiredCount: 1,
        targetHealth: [target('10.0.0.4', 'healthy'), target('10.0.0.5', 'initial')],
    );

    expect($healthy)->toBeFalse();
});

it('is not healthy until the new task is even running', function () {
    $healthy = WaitForDeploymentHealthyStep::newTasksAreHealthy(
        tasks: [task(OLD_REV, '10.0.0.4')],
        newRevision: NEW_REV,
        desiredCount: 1,
        targetHealth: [target('10.0.0.4', 'healthy')],
    );

    expect($healthy)->toBeFalse();
});
