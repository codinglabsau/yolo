<?php

use Codinglabs\Yolo\Audit\LegacyAudit;

/**
 * @param  array<int, array{Key: string, Value: string}>  $tags
 */
function taggedResource(string $arn, array $tags = []): array
{
    return ['ResourceARN' => $arn, 'Tags' => $tags];
}

function nameTag(string $name): array
{
    return [
        ['Key' => 'yolo:environment', 'Value' => 'production'],
        ['Key' => 'Name', 'Value' => $name],
    ];
}

it('collects the IDs of yolo-tagged EC2 instances only', function () {
    $ids = LegacyAudit::ec2InstanceIds([
        taggedResource('arn:aws:ec2:ap-southeast-2:111:instance/i-0aaa'),
        taggedResource('arn:aws:ec2:ap-southeast-2:111:instance/i-0bbb'),
        taggedResource('arn:aws:ec2:ap-southeast-2:111:launch-template/lt-0abc'),
        taggedResource('arn:aws:ecs:ap-southeast-2:111:service/cluster/svc'),
    ]);

    expect($ids)->toBe(['i-0aaa', 'i-0bbb']);
});

it('indexes describeInstances output by instance ID', function () {
    $index = LegacyAudit::indexInstances([
        ['InstanceId' => 'i-0aaa', 'InstanceType' => 't3.medium', 'State' => ['Name' => 'running']],
        ['InstanceId' => 'i-0bbb', 'InstanceType' => 't3.large', 'State' => ['Name' => 'stopped']],
    ]);

    expect($index)->toBe([
        'i-0aaa' => ['type' => 't3.medium', 'state' => 'running'],
        'i-0bbb' => ['type' => 't3.large', 'state' => 'stopped'],
    ]);
});

it('classifies, excludes current resources, prices and groups the inventory', function () {
    $tagged = [
        // legacy compute — always reported
        taggedResource('arn:aws:autoscaling:ap-southeast-2:111:autoScalingGroup:uuid:autoScalingGroupName/yolo-production-web', nameTag('yolo-production-web')),
        taggedResource('arn:aws:ec2:ap-southeast-2:111:instance/i-0run', nameTag('web-1')),
        taggedResource('arn:aws:ec2:ap-southeast-2:111:instance/i-0stop', nameTag('web-2')),
        taggedResource('arn:aws:ec2:ap-southeast-2:111:launch-template/lt-0abc', nameTag('yolo-production-web')),
        taggedResource('arn:aws:codedeploy:ap-southeast-2:111:application:yolo-production', nameTag('yolo-production')),
        // shared ELBv2 — alpha leftovers (names the current deploy doesn't own) kept
        taggedResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:targetgroup/yolo-production-web/old', nameTag('yolo-production-web')),
        taggedResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production-legacy/old', nameTag('yolo-production-legacy')),
        // shared ELBv2 — current deploy owns these, so excluded by name
        taggedResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:targetgroup/yolo-production-app/cur', nameTag('yolo-production-app')),
        taggedResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/cur', nameTag('yolo-production')),
        // current Fargate / shared infra — excluded by type
        taggedResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-app/web', nameTag('yolo-production-app-web')),
        taggedResource('arn:aws:s3:::yolo-production-app-assets', nameTag('yolo-production-app-assets')),
    ];

    $report = LegacyAudit::report(
        taggedResources: $tagged,
        excludedNames: ['yolo-production', 'yolo-production-app'],
        instances: [
            ['InstanceId' => 'i-0run', 'InstanceType' => 't3.medium', 'State' => ['Name' => 'running']],
            ['InstanceId' => 'i-0stop', 'InstanceType' => 't3.large', 'State' => ['Name' => 'stopped']],
        ],
        region: 'ap-southeast-2',
    );

    // 5 legacy compute + 2 alpha ELBv2 leftovers; the 2 current ELBv2, ecs and s3 are dropped
    expect($report['resources'])->toHaveCount(7);

    $byArn = collect($report['resources'])->keyBy('arn');

    // running instance priced from its type; stopped instance is free
    expect($byArn['arn:aws:ec2:ap-southeast-2:111:instance/i-0run']['monthlyCost'])->toBe(38.54);
    expect($byArn['arn:aws:ec2:ap-southeast-2:111:instance/i-0run']['detail'])->toBe('t3.medium · running');
    expect($byArn['arn:aws:ec2:ap-southeast-2:111:instance/i-0stop']['monthlyCost'])->toBe(0.0);
    expect($byArn['arn:aws:ec2:ap-southeast-2:111:instance/i-0stop']['detail'])->toBe('t3.large · stopped');

    // orphaned load balancer bills its baseline; the ASG/launch template/codedeploy are free-standing
    expect($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production-legacy/old']['monthlyCost'])->toBe(18.40);
    expect($byArn['arn:aws:autoscaling:ap-southeast-2:111:autoScalingGroup:uuid:autoScalingGroupName/yolo-production-web']['monthlyCost'])->toBe(0.0);

    // current-deploy ELBv2 resources excluded by name
    expect($byArn->has('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/cur'))->toBeFalse();
    expect($byArn->has('arn:aws:elasticloadbalancing:ap-southeast-2:111:targetgroup/yolo-production-app/cur'))->toBeFalse();

    // totals: only the running instance + the orphaned ALB carry cost
    expect($report['totalMonthlyCost'])->toBe(56.94);
    expect($report['unpricedCount'])->toBe(0);

    $groups = collect($report['groups'])->keyBy('label');
    expect($groups['EC2 instance']['count'])->toBe(2);
    expect($groups['EC2 instance']['monthlyCost'])->toBe(38.54);
    expect($groups['Load balancer']['count'])->toBe(1);
    expect($groups['Load balancer']['monthlyCost'])->toBe(18.40);
});

it('reports a running instance of an unpriced type as unpriced rather than zero', function () {
    $report = LegacyAudit::report(
        taggedResources: [taggedResource('arn:aws:ec2:ap-southeast-2:111:instance/i-0xyz', nameTag('odd'))],
        excludedNames: [],
        instances: [['InstanceId' => 'i-0xyz', 'InstanceType' => 'z1d.metal', 'State' => ['Name' => 'running']]],
        region: 'ap-southeast-2',
    );

    expect($report['resources'][0]['monthlyCost'])->toBeNull();
    expect($report['unpricedCount'])->toBe(1);
    expect($report['totalMonthlyCost'])->toBe(0.0);
});

it('returns an empty report when nothing legacy is tagged', function () {
    $report = LegacyAudit::report(
        taggedResources: [
            taggedResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-app/web', nameTag('svc')),
            taggedResource('arn:aws:s3:::yolo-production-app-assets', nameTag('bucket')),
        ],
        excludedNames: [],
        instances: [],
        region: 'ap-southeast-2',
    );

    expect($report['resources'])->toBe([]);
    expect($report['groups'])->toBe([]);
    expect($report['totalMonthlyCost'])->toBe(0.0);
    expect($report['unpricedCount'])->toBe(0);
});
