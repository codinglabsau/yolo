<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\DeployCheck;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\ChecksIfCommandsShouldBeRunning;
use Codinglabs\Yolo\Steps\Sync\App\SyncExternalDatabaseIngressStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'database' => 'app-db']);
});

function bindExternalInstance(array $securityGroupIds, string $vpcId = 'vpc-external'): void
{
    $captured = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new Result(['DBInstances' => [[
            'DBInstanceIdentifier' => 'app-db',
            'DBSubnetGroup' => ['DBSubnetGroupName' => 'external-group', 'VpcId' => $vpcId],
            'VpcSecurityGroups' => array_map(fn (string $id): array => ['VpcSecurityGroupId' => $id], $securityGroupIds),
        ]]]),
    ], $captured);
}

/** An active peering joining the env VPC and the database's VPC. */
function activePeeringResult(): Result
{
    return new Result(['VpcPeeringConnections' => [[
        'VpcPeeringConnectionId' => 'pcx-1',
        'Status' => ['Code' => 'active'],
        'RequesterVpcInfo' => ['VpcId' => 'vpc-env'],
        'AccepterVpcInfo' => ['VpcId' => 'vpc-external'],
    ]]]);
}

it('authorises 3306 from the task SG on the external database\'s discovered security group', function (): void {
    bindExternalInstance(['sg-external']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env']]]),
        'DescribeVpcPeeringConnections' => activePeeringResult(),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-my-app-ecs-task-security-group', 'GroupId' => 'sg-task'],
        ]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncExternalDatabaseIngressStep())([]))->toBe(StepResult::SYNCED);

    $authorise = collect($captured)->firstWhere('name', 'AuthorizeSecurityGroupIngress');
    expect($authorise['args']['GroupId'])->toBe('sg-external')
        ->and($authorise['args']['IpPermissions'][0]['FromPort'])->toBe(3306)
        ->and($authorise['args']['IpPermissions'][0]['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-task');
});

it('reports pending on the plan pass without writing', function (): void {
    bindExternalInstance(['sg-external']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env']]]),
        'DescribeVpcPeeringConnections' => activePeeringResult(),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-my-app-ecs-task-security-group', 'GroupId' => 'sg-task'],
        ]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $captured);

    $step = new SyncExternalDatabaseIngressStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and($step->changes())->not->toBeEmpty()
        ->and(collect($captured)->pluck('name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('leaves a database in the environment VPC to the managed path', function (): void {
    bindExternalInstance(['sg-rds'], vpcId: 'vpc-env');

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env']]]),
    ], $captured);

    expect((new SyncExternalDatabaseIngressStep())([]))->toBe(StepResult::SKIPPED)
        ->and(collect($captured)->pluck('name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('warns and skips when the external database carries more than one security group', function (): void {
    bindExternalInstance(['sg-one', 'sg-two']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env']]]),
        'DescribeVpcPeeringConnections' => activePeeringResult(),
    ], $captured);

    $step = new SyncExternalDatabaseIngressStep();

    expect($step([]))->toBe(StepResult::SKIPPED)
        ->and($step->recordedWarnings())->toHaveCount(1)
        ->and($step->recordedWarnings()[0])->toContain('ambiguous')
        ->and(collect($captured)->pluck('name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('warns and skips an external database whose VPC is neither peered nor declared', function (): void {
    bindExternalInstance(['sg-external']);

    $s3Captured = [];
    bindRoutedS3Client(['GetObject' => new Result(['Body' => 'peering: []'])], $s3Captured);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env']]]),
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => []]),
    ], $captured);

    $step = new SyncExternalDatabaseIngressStep();

    expect($step([]))->toBe(StepResult::SKIPPED)
        ->and($step->recordedWarnings()[0])->toContain('no peering')
        ->and(collect($captured)->pluck('name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('proceeds when the peering is declared but not yet active — the env tier activates it this sync', function (): void {
    bindExternalInstance(['sg-external'], vpcId: 'vpc-0abc123');

    $s3Captured = [];
    bindRoutedS3Client(['GetObject' => new Result(['Body' => "peering:\n  - vpc-0abc123\n"])], $s3Captured);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env']]]),
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => []]),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-my-app-ecs-task-security-group', 'GroupId' => 'sg-task'],
        ]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $captured);

    $step = new SyncExternalDatabaseIngressStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and($step->changes())->not->toBeEmpty();
});

it('skips when no database is declared', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect((new SyncExternalDatabaseIngressStep())([]))->toBe(StepResult::SKIPPED);
});

it('is skipped by the deploy gate — yolo sync is its drift check', function (): void {
    $checker = new class()
    {
        use ChecksIfCommandsShouldBeRunning;
    };

    $step = new SyncExternalDatabaseIngressStep();

    expect(DeployCheck::during(fn (): ?string => $checker->skipReason($step)))->not->toBeNull()
        ->and($checker->skipReason($step))->toBeNull();
});
