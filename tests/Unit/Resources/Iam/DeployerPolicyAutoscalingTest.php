<?php

use Illuminate\Support\Collection;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;

/**
 * Autoscaling permissions are only granted to the deployer role for apps that opt
 * into autoscaling, so the policy stays least-privilege for everyone else.
 */
function autoscalingActions(): Collection
{
    return collect((new DeployerPolicy())->document()['Statement'])
        ->pluck('Action')
        ->flatten();
}

it('omits autoscaling permissions when no autoscaling block is present', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => []],
    ]);

    expect(autoscalingActions())->not->toContain('application-autoscaling:RegisterScalableTarget');
});

it('grants autoscaling, CloudWatch alarm and service-linked-role permissions when autoscaling is configured', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 4]]],
    ]);

    expect(autoscalingActions())->toContain(
        'application-autoscaling:RegisterScalableTarget',
        'application-autoscaling:PutScalingPolicy',
        'application-autoscaling:DeregisterScalableTarget',
        'cloudwatch:PutMetricAlarm',
        'iam:CreateServiceLinkedRole',
    );
});

it('fences CreateServiceLinkedRole to the ECS autoscaling service principal', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 4]]],
    ]);

    $statement = collect((new DeployerPolicy())->document()['Statement'])
        ->first(fn (array $statement) => in_array('iam:CreateServiceLinkedRole', (array) $statement['Action'], true));

    expect($statement['Condition']['StringEquals']['iam:AWSServiceName'])
        ->toBe('ecs.application-autoscaling.amazonaws.com');
});
