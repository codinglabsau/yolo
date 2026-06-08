<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncCacheSecurityGroupStep;

function describeCacheAndTaskGroups(): Result
{
    return new Result([
        'SecurityGroups' => [
            ['GroupName' => 'yolo-testing-cache-security-group', 'GroupId' => 'sg-cache1'],
            ['GroupName' => 'yolo-testing-my-app-ecs-task-security-group', 'GroupId' => 'sg-task456'],
        ],
    ]);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
    ]);
});

it('skips when cache.store is not redis', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    expect((new SyncCacheSecurityGroupStep())([]))->toBe(StepResult::SKIPPED);
});

it('creates the cache security group and authorises 6379 from the task SG', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => [
            new Result(['SecurityGroups' => []]),  // first lookup → not found → create
            describeCacheAndTaskGroups(),          // re-lookup after create (repeats)
        ],
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'CreateSecurityGroup' => new Result(['GroupId' => 'sg-cache1']),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncCacheSecurityGroupStep())([]))->toBe(StepResult::CREATED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('CreateSecurityGroup')->toContain('AuthorizeSecurityGroupIngress');
    expect($names)->not->toContain('RevokeSecurityGroupIngress');
});

it('additively authorises 6379 from the task SG on an existing cache SG', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeCacheAndTaskGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncCacheSecurityGroupStep())([]))->toBe(StepResult::SYNCED);

    $authorise = collect($captured)->firstWhere('name', 'AuthorizeSecurityGroupIngress');
    $permission = $authorise['args']['IpPermissions'][0];

    expect($permission['FromPort'])->toBe(6379);
    expect($permission['ToPort'])->toBe(6379);
    expect($permission['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-task456');
    expect($authorise['args']['GroupId'])->toBe('sg-cache1');
    expect(array_column($captured, 'name'))->not->toContain('RevokeSecurityGroupIngress');
});

it('does not authorise again when a matching 6379 rule already exists', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeCacheAndTaskGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [
            [
                'IsEgress' => false,
                'IpProtocol' => 'tcp',
                'FromPort' => 6379,
                'ToPort' => 6379,
                'ReferencedGroupInfo' => ['GroupId' => 'sg-task456'],
            ],
        ]]),
    ], $captured);

    (new SyncCacheSecurityGroupStep())([]);

    expect(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('does not authorise during a dry-run', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeCacheAndTaskGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $captured);

    (new SyncCacheSecurityGroupStep())(['dry-run' => true]);

    expect(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});
