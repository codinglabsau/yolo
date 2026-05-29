<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;
use Codinglabs\Yolo\Resources\ElastiCache\CacheParameterGroup;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => true],
    ]);
});

it('is env-scoped', function () {
    expect((new CacheParameterGroup())->scope())->toBe(Scope::Env);
    expect((new CacheParameterGroup())->name())->toBe('yolo-testing-cache-parameter-group');
});

it('creates the parameter group on the pinned family and sets allkeys-lru', function () {
    $captured = [];

    bindMockElastiCacheClient([
        'CreateCacheParameterGroup' => new Result(),
        'ModifyCacheParameterGroup' => new Result(),
    ], $captured);

    (new CacheParameterGroup())->create();

    $create = collect($captured)->firstWhere('name', 'CreateCacheParameterGroup');
    expect($create['args']['CacheParameterGroupName'])->toBe('yolo-testing-cache-parameter-group');
    expect($create['args']['CacheParameterGroupFamily'])->toBe(CacheCluster::PARAMETER_GROUP_FAMILY);

    $modify = collect($captured)->firstWhere('name', 'ModifyCacheParameterGroup');
    expect($modify['args']['ParameterNameValues'][0])->toMatchArray([
        'ParameterName' => 'maxmemory-policy',
        'ParameterValue' => 'allkeys-lru',
    ]);
});
