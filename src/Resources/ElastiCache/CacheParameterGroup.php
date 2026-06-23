<?php

namespace Codinglabs\Yolo\Resources\ElastiCache;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\ElastiCache;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Aws\ElastiCache\Exception\ElastiCacheException;
use Codinglabs\Yolo\Enums\ElastiCache as ElastiCacheEnum;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Custom cache parameter group pinning `maxmemory-policy=allkeys-lru` so the
 * shared cache never refuses a write under memory pressure (it evicts the
 * least-recently-used key instead) — the right general-purpose default for an
 * app cache. The parameter-group family is coupled to the engine major, so it is
 * pinned alongside CacheCluster::ENGINE_VERSION.
 */
class CacheParameterGroup implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(ElastiCacheEnum::CACHE_PARAMETER_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            ElastiCache::cacheParameterGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElastiCache::cacheParameterGroup($this->name())['ARN'];
    }

    public function create(): void
    {
        Aws::elastiCache()->createCacheParameterGroup([
            'CacheParameterGroupName' => $this->name(),
            'CacheParameterGroupFamily' => CacheCluster::PARAMETER_GROUP_FAMILY,
            'Description' => 'YOLO cache parameter group',
            ...Aws::tags($this->tags()),
        ]);

        Aws::elastiCache()->modifyCacheParameterGroup([
            'CacheParameterGroupName' => $this->name(),
            'ParameterNameValues' => [
                ['ParameterName' => 'maxmemory-policy', 'ParameterValue' => 'allkeys-lru'],
            ],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElastiCacheTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Teardown: delete the parameter group, after the cache that used it is gone.
     * A concurrent not-found is tolerated.
     */
    public function delete(): void
    {
        try {
            Aws::elastiCache()->deleteCacheParameterGroup([
                'CacheParameterGroupName' => $this->name(),
            ]);
        } catch (ElastiCacheException $e) {
            if ($e->getAwsErrorCode() === 'CacheParameterGroupNotFound') {
                return;
            }

            throw $e;
        }
    }
}
