<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\RdsSecurityGroup;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Steps\Destroy\App\RevokeRdsIngressStep;
use Codinglabs\Yolo\Steps\Destroy\App\TeardownForwardRuleStep;
use Codinglabs\Yolo\Steps\Destroy\App\TeardownTargetGroupStep;
use Codinglabs\Yolo\Steps\Destroy\App\UnpublishAppManifestStep;
use Codinglabs\Yolo\Steps\Destroy\App\DeregisterWebAutoscalingStep;
use Codinglabs\Yolo\Steps\Destroy\App\DeregisterTaskDefinitionsStep;
use Codinglabs\Yolo\Steps\Destroy\App\TeardownCloudWatchDashboardStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'example.com',
        'tasks' => ['web' => true],
    ]);
});

it('TeardownStep base deletes an existing resource and records the change', function (): void {
    $captured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupName' => 'yolo-testing-my-app', 'TargetGroupArn' => 'arn:tg']]]),
    ], $captured);

    $step = new TeardownTargetGroupStep();

    expect($step(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeleteTargetGroup')
        ->and($step->changes())->not->toBeEmpty();
});

it('TeardownStep base reports WOULD_DELETE without writing on the plan pass', function (): void {
    $captured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupName' => 'yolo-testing-my-app', 'TargetGroupArn' => 'arn:tg']]]),
    ], $captured);

    expect((new TeardownTargetGroupStep())(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(array_column($captured, 'name'))->not->toContain('DeleteTargetGroup');
});

it('revokes only this app\'s 3306 rule from the shared RDS security group', function (): void {
    $rdsName = (new RdsSecurityGroup())->name();
    $taskName = (new EcsTaskSecurityGroup())->name();

    $captured = [];
    bindMockEc2Client([
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => $rdsName, 'GroupId' => 'sg-rds'],
            ['GroupName' => $taskName, 'GroupId' => 'sg-task'],
        ]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [
            ['SecurityGroupRuleId' => 'sgr-1', 'IsEgress' => false, 'IpProtocol' => 'tcp', 'FromPort' => 3306, 'ReferencedGroupInfo' => ['GroupId' => 'sg-task']],
        ]]),
    ], $captured);

    expect((new RevokeRdsIngressStep())(['dry-run' => false]))->toBe(StepResult::DELETED);

    $revoke = collect($captured)->firstWhere('name', 'RevokeSecurityGroupIngress');
    expect($revoke['args']['GroupId'])->toBe('sg-rds')
        ->and($revoke['args']['SecurityGroupRuleIds'])->toBe(['sgr-1']);
});

it('leaves an adopted RDS security group entirely alone', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'domain' => 'example.com',
        'tasks' => ['web' => true], 'rds' => ['security-group' => 'my-existing-sg'],
    ]);

    expect((new RevokeRdsIngressStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED);
});

it('deregisters every active task-definition revision in the app\'s families', function (): void {
    $captured = [];
    bindRoutedEcsClient([
        'ListTaskDefinitions' => new Result(['taskDefinitionArns' => ['arn:td:1', 'arn:td:2']]),
    ], $captured);

    expect((new DeregisterTaskDefinitionsStep())(['dry-run' => false]))->toBe(StepResult::DELETED);

    expect(collect($captured)->where('name', 'DeregisterTaskDefinition')->pluck('args.taskDefinition')->all())
        ->toBe(['arn:td:1', 'arn:td:2']);
});

it('deregisters the web scalable target, cascading its policies', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 1, 'MaxCapacity' => 4]]]),
    ], $captured);

    expect((new DeregisterWebAutoscalingStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeregisterScalableTarget');
});

it('skips deregistering autoscaling when there is no scalable target', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => []]),
    ], $captured);

    expect((new DeregisterWebAutoscalingStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($captured, 'name'))->not->toContain('DeregisterScalableTarget');
});

it('deletes the published claim file from the env config bucket', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'HeadObject' => new Result([]),
        'DeleteObject' => new Result([]),
    ], $captured);

    expect((new UnpublishAppManifestStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeleteObject');
});

it('deletes the CloudWatch dashboard when present', function (): void {
    $captured = [];
    bindMockCloudWatchClient([
        'GetDashboard' => new Result(['DashboardBody' => '{}']),
    ], $captured);

    expect((new TeardownCloudWatchDashboardStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeleteDashboards');
});

it('skips the forward-rule teardown when the load balancer is already gone', function (): void {
    $captured = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => []]),
    ], $captured);

    expect((new TeardownForwardRuleStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED);
});
