<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncCacheSubnetGroupStep;

it('skips when aws.cache is not set', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);

    expect((new SyncCacheSubnetGroupStep())([]))->toBe(StepResult::SKIPPED);
});

it('creates the subnet group when aws.cache is set', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => true],
    ]);

    $ec2 = [];
    $captured = [];

    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSubnets' => new Result(['Subnets' => [['SubnetId' => 'subnet-a']]]),
    ], $ec2);

    bindMockElastiCacheClient([
        'DescribeCacheSubnetGroups' => new Result(['CacheSubnetGroups' => []]),
        'CreateCacheSubnetGroup' => new Result(),
    ], $captured);

    expect((new SyncCacheSubnetGroupStep())([]))->toBe(StepResult::CREATED);
    expect(array_column($captured, 'name'))->toContain('CreateCacheSubnetGroup');
});

it('does not create the subnet group during a dry-run', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => true],
    ]);

    $captured = [];

    bindMockElastiCacheClient([
        'DescribeCacheSubnetGroups' => new Result(['CacheSubnetGroups' => []]),
    ], $captured);

    expect((new SyncCacheSubnetGroupStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(array_column($captured, 'name'))->not->toContain('CreateCacheSubnetGroup');
});
