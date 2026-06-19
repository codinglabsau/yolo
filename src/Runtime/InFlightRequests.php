<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Illuminate\Contracts\Cache\Repository;

/**
 * A task-scoped count of HTTP requests currently in flight — the numerator the burst
 * reporter divides by the worker-pool size to get saturation. It exists because the
 * obvious source, FrankenPHP's `busy_workers` gauge scraped from :2019, reads *wrong
 * under the exact pin burst is meant to catch*: it's sampled from a request's
 * after-response hook (the sampling worker has just finished, so it counts itself idle)
 * and only at the instant a worker freed (a momentary low). Under a CPU-bound SSR pin
 * that snapshot reads ~50% on a 4-worker box that's genuinely at 100% — so the alarm
 * fired only *after* scale-out gave the box headroom, never during the pin. The signal
 * was inverted: high when idle, low when pinned.
 *
 * Counting requests directly sidesteps all of it. {@see Http\TrackInFlightRequests}
 * brackets every request — `enter()` before the pipeline, `leave()` in a `finally`
 * after it — so a request blocked on a synchronous SSR render still counts as in flight,
 * which is precisely the load the worker gauge misses.
 *
 * The reporter reads the window's **peak**, not the instantaneous count, so it's immune
 * to the sampling-instant lull that bit the scrape: even though `leave()` has run by the
 * time the after-response hook reads, the high-water mark already captured the moment
 * every worker was busy. The counter equals busy workers (a request only enters once a
 * worker picks it up — FrankenPHP queues the overflow before PHP), so it tops out at the
 * pool size, never above; a true 100% is plenty to clear the 70% trip.
 *
 * The store is the app cache (Redis on a YOLO app — the same store the reporter already
 * uses for its window claim), so the count spans every worker in the task. Keys are
 * task-scoped, so a shared Redis is correct: each task tracks its own concurrency and the
 * alarm takes Maximum across tasks.
 *
 * Failure mode is deliberately one-directional: a request whose worker dies before
 * `finally` runs (a fatal, a SIGKILL) leaks the counter *up*, biasing toward an extra
 * scale-out / an SSR shed — never toward going dark, the one outcome burst exists to
 * prevent. A leak on a task being recycled dies with the task's keys; a leak on a
 * long-lived task is the documented trade for not depending on a starvable endpoint.
 */
class InFlightRequests
{
    /**
     * Peak-key TTL — comfortably longer than the reporter's poll window so the
     * high-water mark survives between reads, short enough that a recycled task's key
     * clears itself from the shared store soon after the task is gone.
     */
    private const int PEAK_TTL = 30;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $taskId,
    ) {}

    /** A request entered the pipeline: bump the live count and the window's high-water mark. */
    public function enter(): void
    {
        $current = (int) $this->cache->increment($this->key('current'));

        // A racy read-modify-write on the peak is fine: it's a coarse load signal
        // compared against a 70% threshold, so a +1 lost to a race between two enters is
        // immaterial — the next enter records it anyway.
        if ($current > (int) $this->cache->get($this->key('peak'), 0)) {
            $this->cache->put($this->key('peak'), $current, self::PEAK_TTL);
        }
    }

    /** A request left the pipeline. Paired 1:1 with enter() by the middleware's `finally`. */
    public function leave(): void
    {
        $this->cache->decrement($this->key('current'));
    }

    /** The live in-flight count, floored at zero so a stray decrement never reads negative. */
    public function current(): int
    {
        return max(0, (int) $this->cache->get($this->key('current'), 0));
    }

    /**
     * The window's peak in-flight count, then reset the high-water mark to whatever is
     * live right now so the next window measures afresh rather than inheriting an old spike.
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
