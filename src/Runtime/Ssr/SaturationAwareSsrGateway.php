<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Ssr;

use Throwable;
use Inertia\Ssr\Response;
use Inertia\Ssr\HttpGateway;
use Illuminate\Support\Facades\Http;
use Codinglabs\Yolo\YoloServiceProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\StrayRequestException;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;

/**
 * An Inertia SSR gateway that sheds rendering under load. It extends the stock
 * {@see HttpGateway} — inheriting its enable/bundle gating ({@see HttpGateway::shouldDispatch()})
 * and URL resolution — and overrides only dispatch() to add the two protections the
 * default gateway lacks:
 *
 *  1. Saturation bypass. When the burst engine has flagged this task as hot — the
 *     same per-task worker-saturation reading that drives step-scaling, set by
 *     {@see WorkerSaturationReporter} — skip the Node render entirely and let Inertia
 *     fall back to CSR. This sheds the most expensive per-request CPU exactly when
 *     CPU is scarce, instantly and locally (no CloudWatch round-trip), buying time
 *     for the slower scale-out to land.
 *
 *  2. A bounded render timeout. The stock gateway POSTs to Node with no timeout, so a
 *     slow render blocks the worker for Laravel's HTTP default (~30s). A tight bound
 *     frees the worker fast; Inertia already turns a failed dispatch into CSR. This is
 *     the backstop covering the few seconds before the saturation flag trips.
 *
 * Bound from {@see YoloServiceProvider} on the autoscaling web tier
 * only (the same gate that runs the reporter), so it's inert everywhere else.
 */
class SaturationAwareSsrGateway extends HttpGateway
{
    /**
     * The render budget, in seconds. Deliberately generous: the saturation bypass is
     * the real load shedder, so the timeout only needs to catch an individual
     * pathological render — a tight value would needlessly degrade a legitimately slow
     * first render to CSR (and silently cost its SEO).
     */
    public const float RENDER_TIMEOUT = 2.0;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $taskId,
    ) {}

    /**
     * @param  array<string, mixed>  $page
     */
    #[\Override]
    public function dispatch(array $page): ?Response
    {
        if (! $this->shouldDispatch()) {
            return null;
        }

        // Hot box → shed SSR before we ever touch Node. Inertia renders CSR. The flag
        // is set by the burst reporter while saturated and self-expires on cooldown.
        if ($this->cache->get(WorkerSaturationReporter::ssrBypassKey($this->taskId))) {
            return null;
        }

        try {
            $response = Http::connectTimeout(1)
                ->timeout(self::RENDER_TIMEOUT)
                ->post($this->getUrl('/render'), $page)
                ->throw()
                ->json();
        } catch (StrayRequestException $e) {
            throw $e; // keep strict-HTTP-fake tests honest, exactly as HttpGateway does
        } catch (Throwable) {
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
}
