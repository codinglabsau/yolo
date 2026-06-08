<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncCacheParameterGroupStep;

it('skips when cache.store is not redis', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    expect((new SyncCacheParameterGroupStep())([]))->toBe(StepResult::SKIPPED);
});

it('creates the parameter group when cache.store is redis', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
    ]);

    $captured = [];

    bindMockElastiCacheClient([
        'DescribeCacheParameterGroups' => new Result(['CacheParameterGroups' => []]),
        'CreateCacheParameterGroup' => new Result(),
        'ModifyCacheParameterGroup' => new Result(),
    ], $captured);

    expect((new SyncCacheParameterGroupStep())([]))->toBe(StepResult::CREATED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('CreateCacheParameterGroup')->toContain('ModifyCacheParameterGroup');
});
