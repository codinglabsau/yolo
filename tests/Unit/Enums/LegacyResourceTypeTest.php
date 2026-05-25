<?php

use Codinglabs\Yolo\Enums\LegacyResourceType;

it('classifies alpha-era ARNs by service and resource type', function (string $arn, LegacyResourceType $expected) {
    expect(LegacyResourceType::tryFromArn($arn))->toBe($expected);
})->with([
    'asg' => ['arn:aws:autoscaling:ap-southeast-2:111:autoScalingGroup:uuid:autoScalingGroupName/yolo-production-web', LegacyResourceType::AutoScalingGroup],
    'instance' => ['arn:aws:ec2:ap-southeast-2:111:instance/i-0abc', LegacyResourceType::Ec2Instance],
    'launch template' => ['arn:aws:ec2:ap-southeast-2:111:launch-template/lt-0abc', LegacyResourceType::LaunchTemplate],
    'key pair' => ['arn:aws:ec2:ap-southeast-2:111:key-pair/yolo-production', LegacyResourceType::KeyPair],
    'codedeploy app' => ['arn:aws:codedeploy:ap-southeast-2:111:application:yolo-production', LegacyResourceType::CodeDeployApplication],
    'codedeploy group' => ['arn:aws:codedeploy:ap-southeast-2:111:deploymentgroup:app/group', LegacyResourceType::CodeDeployDeploymentGroup],
    'target group' => ['arn:aws:elasticloadbalancing:ap-southeast-2:111:targetgroup/yolo-production-app/abc', LegacyResourceType::TargetGroup],
    'load balancer' => ['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc', LegacyResourceType::LoadBalancer],
]);

it('returns null for current Fargate or shared resources', function (string $arn) {
    expect(LegacyResourceType::tryFromArn($arn))->toBeNull();
})->with([
    'ecs service' => ['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-app/yolo-production-app-web'],
    'ecr repo' => ['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-app'],
    's3 bucket' => ['arn:aws:s3:::yolo-production-app-assets'],
    'log group' => ['arn:aws:logs:ap-southeast-2:111:log-group:/yolo/production-app'],
    'garbage' => ['not-an-arn'],
]);

it('marks ELBv2 types as shared and compute primitives as not shared', function () {
    expect(LegacyResourceType::TargetGroup->isShared())->toBeTrue()
        ->and(LegacyResourceType::LoadBalancer->isShared())->toBeTrue()
        ->and(LegacyResourceType::Ec2Instance->isShared())->toBeFalse()
        ->and(LegacyResourceType::AutoScalingGroup->isShared())->toBeFalse();
});

it('gives each case a human-readable label', function () {
    expect(LegacyResourceType::AutoScalingGroup->label())->toBe('Auto Scaling group')
        ->and(LegacyResourceType::CodeDeployDeploymentGroup->label())->toBe('CodeDeploy deployment group');
});
