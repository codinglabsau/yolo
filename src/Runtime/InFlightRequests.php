<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Illuminate\Contracts\Cache\Repository;

/**
 * The saturation numerator on an Octane tier (a classic tier reads its thread gauges and
 * never tracks this), counted directly because FrankenPHP's `busy_workers` gauge reads
 * wrong under the exact pin burst is meant to catch: sampled from a request's
 * after-response hook, the sampling worker has just finished (so counts itself idle) and
 * only at the instant a worker freed — a momentary low. Under a CPU-bound SSR pin it read
 * ~50% on a box genuinely at 100%, so the alarm fired only after scale-out gave headroom.
 *
 * The reporter reads the window's peak, not the instantaneous count, so it's
 * immune to that sampling-instant lull even though `leave()` has run by the time
 * the hook reads. A request only enters once a worker picks it up (FrankenPHP
 * queues overflow before PHP), so the count tops out at the pool size.
 *
 * Keys are task-scoped in the app cache, so a shared Redis is correct: each task
 * tracks its own concurrency and the alarm takes Maximum across tasks.
 *
 * Failure mode is deliberately one-directional: a worker that dies before
 * `finally` runs leaks the counter UP — toward an extra scale-out or SSR shed,
 * never toward going dark. That's the trade for not depending on a starvable
 * endpoint.
 */
class InFlightRequests
{
    /** Longer than the poll window so the peak survives between reads; short enough that a recycled task's key clears itself. */
    private const int PEAK_TTL = 30;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $taskId,
    ) {}

    public function enter(): void
    {
        $current = (int) $this->cache->increment($this->key('current'));

        // A racy read-modify-write is fine: a +1 lost between two enters is
        // immaterial against a 70% threshold, and the next enter records it anyway.
        if ($current > (int) $this->cache->get($this->key('peak'), 0)) {
            $this->cache->put($this->key('peak'), $current, self::PEAK_TTL);
        }
    }

    public function leave(): void
    {
        $this->cache->decrement($this->key('current'));
    }

    public function current(): int
    {
        return max(0, (int) $this->cache->get($this->key('current'), 0));
    }

    /**
     * Resets the high-water mark to what's live now, so the next window doesn't
     * inherit an old spike.
     */
    public function flushPeak(): int
    {
        $current = $this->current();
        $peak = max($current, (int) $this->cache->get($this->key('peak'), 0));

        $this->cache->put($this->key('peak'), $current, self::PEAK_TTL);

        return $peak;
    }

    private function key(string $suffix): string
    {
        return "yolo-burst:{$this->taskId}:inflight:{$suffix}";
    }
}
