<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Contracts;

use Codinglabs\Yolo\Runtime\CacheWindowStore;

/**
 * The tiny slice of a shared cache the reporter needs: an atomic "claim this
 * window" plus plain get/put/forget, all keyed per task. Backed by Redis in
 * production ({@see CacheWindowStore}) so the debounce and
 * panic state survive across the web tier's worker processes within a task; an
 * in-memory fake stands in for tests.
 */
interface WindowStore
{
    /**
     * Atomically claim $key for $ttlSeconds. Returns true to exactly one caller
     * while the key is unset, false to everyone else — the debounce that makes the
     * reporter do real work at most once per window no matter the request rate.
     */
    public function add(string $key, int $ttlSeconds): bool;

    public function get(string $key): ?int;

    public function put(string $key, int $value, int $ttlSeconds): void;

    public function forget(string $key): void;
}
