<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Illuminate\Contracts\Cache\Repository;
use Codinglabs\Yolo\Runtime\Contracts\WindowStore;

/**
 * The production {@see WindowStore}: the app's own cache repository (Redis on a
 * YOLO app), so the per-task debounce and panic state are shared across the web
 * tier's worker processes within a task. The keys are task-scoped by the reporter,
 * so a shared Redis is correct — each task still publishes its own datapoint and
 * the burst alarm takes Maximum across them.
 */
class CacheWindowStore implements WindowStore
{
    public function __construct(private readonly Repository $cache) {}

    public function add(string $key, int $ttlSeconds): bool
    {
        return $this->cache->add($key, 1, $ttlSeconds);
    }

    public function get(string $key): ?int
    {
        $value = $this->cache->get($key);

        return $value === null ? null : (int) $value;
    }

    public function put(string $key, int $value, int $ttlSeconds): void
    {
        $this->cache->put($key, $value, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }
}
