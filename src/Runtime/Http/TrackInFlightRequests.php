<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Http;

use Closure;
use Illuminate\Http\Request;
use Codinglabs\Yolo\Runtime\InFlightRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brackets every request for {@see InFlightRequests}; pushed onto the global
 * stack on the autoscaling web tier only. The `finally` keeps enter/leave paired
 * even when the request throws. It deliberately does not implement
 * `terminate()`: terminable middleware runs BEFORE the app's terminating
 * callbacks, and the count must still be live when the reporter reads the peak.
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
