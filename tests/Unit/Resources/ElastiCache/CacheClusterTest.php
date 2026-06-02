<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
    ]);
});

it('is env-scoped and shared (no app segment, no yolo:app tag)', function () {
    $resource = new CacheCluster();

    expect($resource->scope())->toBe(Scope::Env);
    expect($resource->name())->toBe('yolo-testing-cache');
    expect($resource->tags())->not->toHaveKey('yolo:app');
});

it('creates a single-node Valkey replication group locked to the cache SG', function () {
    $ec2 = [];
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-cache-security-group', 'GroupId' => 'sg-cache1'],
        ]]),
    ], $ec2);

    bindMockElastiCacheClient([
        'CreateReplicationGroup' => new Result(),
        'DescribeReplicationGroups' => new Result(['ReplicationGroups' => [
            ['ReplicationGroupId' => 'yolo-testing-cache', 'Status' => 'available'],
        ]]),
    ], $captured);

    (new CacheCluster())->create();

    $call = collect($captured)->firstWhere('name', 'CreateReplicationGroup');

    expect($call['args']['ReplicationGroupId'])->toBe('yolo-testing-cache');
    expect($call['args']['Engine'])->toBe('valkey');
    expect($call['args']['EngineVersion'])->toBe('9.0');
    expect($call['args']['CacheNodeType'])->toBe('cache.t4g.micro');
    expect($call['args']['NumCacheClusters'])->toBe(1);
    expect($call['args']['AutomaticFailoverEnabled'])->toBeFalse();
    expect($call['args']['MultiAZEnabled'])->toBeFalse();
    expect($call['args']['AtRestEncryptionEnabled'])->toBeTrue();
    expect($call['args']['TransitEncryptionEnabled'])->toBeFalse();
    expect($call['args']['Port'])->toBe(6379);
    expect($call['args']['CacheSubnetGroupName'])->toBe('yolo-testing-cache-subnet-group');
    expect($call['args']['CacheParameterGroupName'])->toBe('yolo-testing-cache-parameter-group');
    expect($call['args']['SecurityGroupIds'])->toBe(['sg-cache1']);
});

it('reads the primary endpoint address', function () {
    $captured = [];

    bindMockElastiCacheClient([
        'DescribeReplicationGroups' => new Result(['ReplicationGroups' => [
            [
                'ReplicationGroupId' => 'yolo-testing-cache',
                'NodeGroups' => [
                    ['PrimaryEndpoint' => ['Address' => 'master.yolo-testing-cache.abc123.apse2.cache.amazonaws.com']],
                ],
            ],
        ]]),
    ], $captured);

    expect((new CacheCluster())->endpoint())
        ->toBe('master.yolo-testing-cache.abc123.apse2.cache.amazonaws.com');
});
