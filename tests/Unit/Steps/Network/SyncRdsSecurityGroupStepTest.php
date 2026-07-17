<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncRdsSecurityGroupStep;

function describeRdsAndTaskGroups(): Result
{
    return new Result([
        'SecurityGroups' => [
            ['GroupName' => 'yolo-testing-rds-security-group', 'GroupId' => 'sg-rds123', 'VpcId' => 'vpc-1'],
            ['GroupName' => 'yolo-testing-my-app-ecs-task-security-group', 'GroupId' => 'sg-task456', 'VpcId' => 'vpc-1'],
        ],
    ]);
}

/**
 * The base mock map for an existing YOLO-owned RDS SG: the VPC the lookup is
 * scoped to, the RDS + task groups, and live tags already matching desired.
 *
 * @return array<string, Result>
 */
function rdsSecurityGroupMocks(): array
{
    return [
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSecurityGroups' => describeRdsAndTaskGroups(),
        'DescribeTags' => new Result(['Tags' => [
            ['Key' => 'Name', 'Value' => 'yolo-testing-rds-security-group'],
            ['Key' => 'yolo:scope', 'Value' => 'env'],
            ['Key' => 'yolo:environment', 'Value' => 'testing'],
        ]]),
    ];
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('creates the RDS security group and adds the task-SG ingress rule when absent', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => [
            new Result(['SecurityGroups' => []]),   // first lookup → not found → create
            describeRdsAndTaskGroups(),             // re-lookup after create (repeats)
        ],
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'CreateSecurityGroup' => new Result(['GroupId' => 'sg-rds123']),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncRdsSecurityGroupStep())([]))->toBe(StepResult::CREATED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('CreateSecurityGroup');
    expect($names)->toContain('AuthorizeSecurityGroupIngress');
    expect($names)->not->toContain('RevokeSecurityGroupIngress');
});

it('additively authorises 3306 from the task security group on an existing RDS SG', function (): void {
    $captured = [];

    bindMockEc2Client([
        ...rdsSecurityGroupMocks(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncRdsSecurityGroupStep())([]))->toBe(StepResult::SYNCED);

    $authorise = collect($captured)->firstWhere('name', 'AuthorizeSecurityGroupIngress');
    expect($authorise)->not->toBeNull();

    $permission = $authorise['args']['IpPermissions'][0];
    expect($permission['FromPort'])->toBe(3306);
    expect($permission['ToPort'])->toBe(3306);
    expect($permission['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-task456');
    expect($authorise['args']['GroupId'])->toBe('sg-rds123');

    // Purely additive — it must never revoke an existing rule.
    expect(array_column($captured, 'name'))->not->toContain('RevokeSecurityGroupIngress');
});

it('does not authorise again when a matching task-SG rule already exists', function (): void {
    $captured = [];

    bindMockEc2Client([
        ...rdsSecurityGroupMocks(),
        // An existing 3306-from-task-SG rule — note it carries no marker tag, so
        // matching by content (not a tag) is what lets us spot it.
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [
            [
                'SecurityGroupRuleId' => 'sgr-existing',
                'IsEgress' => false,
                'IpProtocol' => 'tcp',
                'FromPort' => 3306,
                'ToPort' => 3306,
                'ReferencedGroupInfo' => ['GroupId' => 'sg-task456'],
            ],
        ]]),
    ], $captured);

    (new SyncRdsSecurityGroupStep())([]);

    expect(array_column($captured, 'name'))
        ->not->toContain('AuthorizeSecurityGroupIngress')
        ->not->toContain('RevokeSecurityGroupIngress');
});

it('does not authorise during a dry-run', function (): void {
    $captured = [];

    bindMockEc2Client([
        ...rdsSecurityGroupMocks(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $captured);

    (new SyncRdsSecurityGroupStep())(['dry-run' => true]);

    expect(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});
