<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Ssr;

use Throwable;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response;
use Illuminate\Support\Facades\Http;
use Codinglabs\Yolo\YoloServiceProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\StrayRequestException;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;

/**
 * An Inertia SSR gateway that protects the web tier under load. It replaces the stock
 * gateway and adds the two things the default lacks, then renders against the same local
 * Node SSR server:
 *
 *  1. A bounded render timeout. Inertia's own gateway POSTs to Node with no timeout, so a
 *     slow render blocks the worker for Laravel's HTTP default (~30s) — and a worker
 *     blocked on a synchronous, CPU-bound render is exactly how one hot task spirals into
 *     a health-check death-loop. A tight bound frees the worker fast; a failed render is
 *     just CSR. This covers the few seconds before the saturation flag (below) trips.
 *
 *  2. Saturation bypass. While the burst engine has flagged this task hot — the same
 *     per-task worker-saturation reading that drives step-scaling, set by
 *     {@see WorkerSaturationReporter} — skip the render entirely and fall back to CSR.
 *     Sheds the most expensive per-request CPU exactly when CPU is scarce, instantly and
 *     locally, buying time for the slower scale-out to land.
 *
 * It implements the `Gateway` interface and talks to the SSR server over the stable
 * `inertia.ssr.*` config + `/render` protocol, rather than extending the stock
 * HttpGateway, because that interface and protocol are stable across Inertia v2 and v3
 * whereas HttpGateway's internals are not (v3 reworked its method set) and an SSR app may
 * be on either major. The one thing it doesn't carry over is v3's per-path SSR exclusion
 * (`ExcludesSsrPaths`), which a replacing gateway can't see — a documented follow-up.
 *
 * Bound from {@see YoloServiceProvider} on the autoscaling web tier only
 * (the same gate that runs the reporter), so it's inert everywhere else.
 */
class SaturationAwareSsrGateway implements Gateway
{
    /**
     * The render budget, in seconds. Generous on purpose: the saturation bypass is the
     * real load shedder, so the timeout only needs to catch an individual slow render — a
     * tight value would needlessly degrade a legitimately slow first render to CSR.
     */
    public const float RENDER_TIMEOUT = 2.0;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $taskId,
    ) {}

    /**
     * @param  array<string, mixed>  $page
     */
    public function dispatch(array $page): ?Response
    {
        if (! config('inertia.ssr.enabled', true)) {
            return null;
        }

        // Hot box → shed SSR before we touch Node. Returning null is Inertia's CSR path.
        if ($this->cache->get(WorkerSaturationReporter::ssrBypassKey($this->taskId))) {
            return null;
        }

        try {
            $response = Http::connectTimeout(1)
                ->timeout(self::RENDER_TIMEOUT)
                ->post($this->renderUrl(), $page)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            // A stray request under Http::preventStrayRequests() must surface, not be
            // swallowed as a CSR fallback — keep strict-HTTP-fake tests honest.
            if ($e instanceof StrayRequestException) {
                throw $e;
            }

            return null; // timeout / connection refused / Node 5xx → CSR fallback
        }

        if (is_null($response)) {
            return null;
        }

        return new Response(
            implode("\n", $response['head']),
            $response['body'],
        );
    }

    private function renderUrl(): string
    {
        $base = rtrim((string) config('inertia.ssr.url', 'http://127.0.0.1:13714'), '/');

        return $base . '/render';
    }
}
