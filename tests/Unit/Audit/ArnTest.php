<?php

use Codinglabs\Yolo\Audit\Arn;

it('parses an EC2 instance ARN', function () {
    $arn = Arn::parse('arn:aws:ec2:ap-southeast-2:111122223333:instance/i-0abc123');

    expect($arn->service)->toBe('ec2')
        ->and($arn->region)->toBe('ap-southeast-2')
        ->and($arn->accountId)->toBe('111122223333')
        ->and($arn->resourceType)->toBe('instance')
        ->and($arn->resourceId)->toBe('i-0abc123');
});

it('parses a launch template ARN', function () {
    $arn = Arn::parse('arn:aws:ec2:ap-southeast-2:111122223333:launch-template/lt-0abc');

    expect($arn->resourceType)->toBe('launch-template')
        ->and($arn->resourceId)->toBe('lt-0abc');
});

it('parses an Auto Scaling group ARN with colons inside the resource segment', function () {
    $arn = Arn::parse('arn:aws:autoscaling:ap-southeast-2:111122223333:autoScalingGroup:uuid-123:autoScalingGroupName/yolo-production-web');

    expect($arn->service)->toBe('autoscaling')
        ->and($arn->resourceType)->toBe('autoScalingGroup')
        ->and($arn->resourceId)->toBe('uuid-123:autoScalingGroupName/yolo-production-web');
});

it('parses a CodeDeploy deployment group ARN', function () {
    $arn = Arn::parse('arn:aws:codedeploy:ap-southeast-2:111122223333:deploymentgroup:my-app/my-group');

    expect($arn->service)->toBe('codedeploy')
        ->and($arn->resourceType)->toBe('deploymentgroup')
        ->and($arn->resourceId)->toBe('my-app/my-group');
});

it('parses an ELBv2 target group ARN', function () {
    $arn = Arn::parse('arn:aws:elasticloadbalancing:ap-southeast-2:111122223333:targetgroup/yolo-production-app/abc123');

    expect($arn->service)->toBe('elasticloadbalancing')
        ->and($arn->resourceType)->toBe('targetgroup')
        ->and($arn->resourceId)->toBe('yolo-production-app/abc123');
});

it('parses an ELBv2 application load balancer ARN', function () {
    $arn = Arn::parse('arn:aws:elasticloadbalancing:ap-southeast-2:111122223333:loadbalancer/app/yolo-production/abc123');

    expect($arn->resourceType)->toBe('loadbalancer')
        ->and($arn->resourceId)->toBe('app/yolo-production/abc123');
});

it('parses an ARN whose resource segment is a bare id (no type)', function () {
    $arn = Arn::parse('arn:aws:s3:::my-bucket');

    expect($arn->service)->toBe('s3')
        ->and($arn->resourceType)->toBe('')
        ->and($arn->resourceId)->toBe('my-bucket');
});

it('returns null for a non-ARN string', function () {
    expect(Arn::parse('not-an-arn'))->toBeNull()
        ->and(Arn::parse('arn:aws:ec2:incomplete'))->toBeNull();
});
