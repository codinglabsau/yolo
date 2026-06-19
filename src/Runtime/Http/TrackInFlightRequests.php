<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Http;

use Closure;
use Illuminate\Http\Request;
use Codinglabs\Yolo\Runtime\InFlightRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brackets every request so {@see InFlightRequests} knows the live concurrency the burst
 * reporter scales on. Pushed onto the global stack on the autoscaling web tier only (the
 * same gate that runs the reporter), so it's inert everywhere else.
 *
 * `enter()` runs before the pipeline and `leave()` in a `finally` after it, which buys
 * two things at once: the count holds for the whole request — including the synchronous
 * SSR render, the expensive load the worker gauge fails to see — and the `finally`
 * guarantees the two are paired even when the request throws, so the count can't drift on
 * an error path. (A worker killed mid-request before `finally` runs leaks the count up,
 * not down — the safe direction; see {@see InFlightRequests}.)
 *
 * It does not implement `terminate()`: the count must still be live when the reporter's
 * after-response hook reads the peak, and terminable middleware runs *before* the app's
 * terminating callbacks. The `finally` is the correct seam.
 */
class TrackInFlightRequests
{
    public function __construct(private readonly InFlightRequests $inFlight) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->inFlight->enter();

        try {
            return $next($request);
        } finally {
            $this->inFlight->leave();
        }
    }
}
