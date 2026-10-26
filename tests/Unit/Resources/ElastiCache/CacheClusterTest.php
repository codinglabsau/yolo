<?php

use Aws\Result;
use Aws\Command;
use Codinglabs\Yolo\Aws;
use GuzzleHttp\Psr7\Response;
use Codinglabs\Yolo\Enums\Scope;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\ElastiCache\Exception\ElastiCacheException;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncCacheClusterStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
    ]);
});

/** Null leaves the env manifest unseeded, as on a greenfield sync. */
function declareCacheNodeType(?string $nodeType): void
{
    $captured = [];

    bindRoutedS3Client([
        'GetObject' => $nodeType === null
            ? new S3Exception('Not Found', new Command('GetObject'), ['response' => new Response(404)])
            : new Result(['Body' => "cache:\n  node-type: {$nodeType}\n"]),
    ], $captured);
}

/**
 * @return array<string, mixed>
 */
function cacheReplicationGroup(string $nodeType, string $status = 'available'): array
{
    return [
        'ReplicationGroupId' => 'yolo-testing-cache',
        'ARN' => 'arn:aws:elasticache:ap-southeast-2:111111111111:replicationgroup:yolo-testing-cache',
        'Status' => $status,
        'CacheNodeType' => $nodeType,
    ];
}

function bindCacheGroupSequence(array $groups, array &$captured, array $extra = []): void
{
    bindMockElastiCacheClient([
        'DescribeReplicationGroups' => array_map(fn (array $group): Result => new Result(['ReplicationGroups' => [$group]]), $groups),
        ...$extra,
    ], $captured);
}

it('is env-scoped and shared (no app segment, no yolo:app tag)', function (): void {
    $resource = new CacheCluster();

    expect($resource->scope())->toBe(Scope::Env);
    expect($resource->name())->toBe('yolo-testing-cache');
    expect($resource->tags())->not->toHaveKey('yolo:app');
});

it('creates a single-node Valkey replication group locked to the cache SG', function (): void {
    declareCacheNodeType(null);

    $ec2 = [];
    $captured = [];

    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
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

it('reads the primary endpoint address', function (): void {
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

it('creates on the node type the env manifest declares', function (): void {
    declareCacheNodeType('cache.m7g.large');

    $ec2 = [];
    $captured = [];

    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-cache-security-group', 'GroupId' => 'sg-cache1'],
        ]]),
    ], $ec2);

    bindCacheGroupSequence([cacheReplicationGroup('cache.m7g.large')], $captured, [
        'CreateReplicationGroup' => new Result(),
    ]);

    (new CacheCluster())->create();

    expect(collect($captured)->firstWhere('name', 'CreateReplicationGroup')['args']['CacheNodeType'])->toBe('cache.m7g.large');
});

it('reports no drift when the live node type matches the default', function (): void {
    declareCacheNodeType(null);

    $captured = [];
    bindCacheGroupSequence([cacheReplicationGroup('cache.t4g.micro')], $captured);

    expect((new CacheCluster())->synchroniseConfiguration(apply: true))->toBe([])
        ->and(collect($captured)->pluck('name'))->not->toContain('ModifyReplicationGroup');
});

it('resizes in place on drift, waits for the group, and reports the change', function (): void {
    declareCacheNodeType('cache.m7g.large');

    $captured = [];
    bindCacheGroupSequence([
        cacheReplicationGroup('cache.t4g.micro'),
        // the waiter's poll, then the post-resize confirmation read
        cacheReplicationGroup('cache.m7g.large'),
        cacheReplicationGroup('cache.m7g.large'),
    ], $captured, [
        'ModifyReplicationGroup' => new Result(),
    ]);

    $changes = (new CacheCluster())->synchroniseConfiguration(apply: true);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->describe())->toBe('node type: cache.t4g.micro → cache.m7g.large');

    $modify = collect($captured)->firstWhere('name', 'ModifyReplicationGroup');

    expect($modify['args']['ReplicationGroupId'])->toBe('yolo-testing-cache')
        ->and($modify['args']['CacheNodeType'])->toBe('cache.m7g.large')
        ->and($modify['args']['ApplyImmediately'])->toBeTrue()
        ->and(collect($captured)->pluck('name')->last())->toBe('DescribeReplicationGroups');
});

it('lets an in-flight modification settle before resizing', function (): void {
    declareCacheNodeType('cache.m7g.large');

    $captured = [];
    bindCacheGroupSequence([
        cacheReplicationGroup('cache.t4g.micro', 'modifying'),
        cacheReplicationGroup('cache.t4g.micro'),
        cacheReplicationGroup('cache.t4g.micro'),
        cacheReplicationGroup('cache.m7g.large'),
    ], $captured, [
        'ModifyReplicationGroup' => new Result(),
    ]);

    (new CacheCluster())->synchroniseConfiguration(apply: true);

    $names = collect($captured)->pluck('name')->all();

    expect(array_search('ModifyReplicationGroup', $names, true))->toBe(3);
});

it('plans a resize on the dry-run pass without modifying the group', function (): void {
    declareCacheNodeType('cache.m7g.large');

    $captured = [];
    bindCacheGroupSequence([cacheReplicationGroup('cache.t4g.micro')], $captured, [
        'ListTagsForResource' => new Result(['TagList' => collect(Aws::expectedTags((new CacheCluster())->tags()))
            ->map(fn (string $value, string $key): array => ['Key' => $key, 'Value' => $value])
            ->values()
            ->all()]),
    ]);

    $step = new SyncCacheClusterStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and(collect($step->changes())->map->describe()->all())->toBe(['node type: cache.t4g.micro → cache.m7g.large'])
        ->and(collect($captured)->pluck('name'))->not->toContain('ModifyReplicationGroup');
});

it('surfaces a rejected resize rather than reporting success', function (): void {
    declareCacheNodeType('cache.m7g.large');

    $captured = [];
    bindCacheGroupSequence([cacheReplicationGroup('cache.t4g.micro')], $captured, [
        'ModifyReplicationGroup' => new ElastiCacheException('Insufficient capacity', new Command('ModifyReplicationGroup'), [
            'code' => 'InsufficientCacheClusterCapacity',
        ]),
    ]);

    expect(fn (): array => (new CacheCluster())->synchroniseConfiguration(apply: true))
        ->toThrow(ElastiCacheException::class, 'Insufficient capacity');
});

it('fails when the group settles back on the old node type', function (): void {
    declareCacheNodeType('cache.m7g.large');

    $captured = [];
    bindCacheGroupSequence([cacheReplicationGroup('cache.t4g.micro')], $captured, [
        'ModifyReplicationGroup' => new Result(),
    ]);

    expect(fn (): array => (new CacheCluster())->synchroniseConfiguration(apply: true))
        ->toThrow(IntegrityCheckException::class, 'still on cache.t4g.micro');
});
