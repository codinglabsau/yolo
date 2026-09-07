<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Illuminate\Contracts\Cache\Repository;
use Codinglabs\Yolo\Runtime\Http\TrackInFlightRequests;

/**
 * The window's peak in-flight requests inside the app on an Octane tier (a classic tier
 * reads its thread gauges and never tracks this), bracketed by {@see TrackInFlightRequests}.
 * It is the floor under the saturation numerator, not the numerator: FrankenPHP's
 * `busy_workers` counts every request dispatched to the worker script, those still
 * waiting for a worker included, so under sustained overload it reads the front queue
 * and the time a busy worker spends outside Laravel's handle span — where this counter,
 * entered only once a worker has picked the request up and the framework is handling
 * it, peaks at a fraction of the pool. It stays because a scrape samples one instant: a
 * reading that lands on a momentary low can't drag the window below the concurrency the
 * app itself saw.
 *
 * The reporter reads the window's peak, not the instantaneous count, so the
 * sampling-instant lull doesn't matter even though `leave()` has run by the time
 * the after-response hook reads. A request only enters once a worker picks it up
 * (FrankenPHP queues overflow before PHP), so the count tops out at the pool size.
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
        return max(0, $this->raw());
    }

    /**
     * The counter as stored, unclamped. A value that has drifted negative reads as a
     * permanent under-count through {@see current()}, so the diagnostics log this
     * rather than the clamped view.
     */
    public function raw(): int
    {
        return (int) $this->cache->get($this->key('current'), 0);
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
