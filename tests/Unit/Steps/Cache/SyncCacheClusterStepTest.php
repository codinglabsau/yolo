<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncCacheClusterStep;

it('skips when cache.store is not redis', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    expect((new SyncCacheClusterStep())([]))->toBe(StepResult::SKIPPED);
});

it('creates the replication group when cache.store is redis', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
    ]);

    $ec2 = [];
    $captured = [];

    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-cache-security-group', 'GroupId' => 'sg-cache1'],
        ]]),
    ], $ec2);

    bindMockElastiCacheClient([
        'DescribeReplicationGroups' => [
            new Result(['ReplicationGroups' => []]),  // exists() → not found → create
            new Result(['ReplicationGroups' => [      // waiter → available
                ['ReplicationGroupId' => 'yolo-testing-cache', 'Status' => 'available'],
            ]]),
        ],
        'CreateReplicationGroup' => new Result(),
    ], $captured);

    expect((new SyncCacheClusterStep())([]))->toBe(StepResult::CREATED);
    expect(array_column($captured, 'name'))->toContain('CreateReplicationGroup');
});
