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
 * Replaces the stock Inertia SSR gateway with two protections: a bounded render
 * timeout (the stock gateway POSTs to Node with no timeout, so a slow render
 * blocks the worker ~30s — how one hot task spirals into a health-check
 * death-loop) and a saturation bypass that skips the render entirely while
 * {@see WorkerSaturationReporter} has flagged the task hot — shedding the most
 * expensive per-request CPU instantly and locally while scale-out lands.
 *
 * It implements the `Gateway` interface over the `inertia.ssr.*` + `/render`
 * protocol rather than extending HttpGateway because those are stable across
 * Inertia v2 and v3 whereas HttpGateway's internals are not. It doesn't carry
 * v3's per-path SSR exclusion (`ExcludesSsrPaths`) — a documented follow-up.
 * Bound from {@see YoloServiceProvider} on the autoscaling web tier only.
 *
 * Container binding is last-writer-wins, so an app that rebinds
 * `Inertia\Ssr\Gateway` silently drops both protections with no error. An app
 * needing custom SSR behaviour must EXTEND this class and call
 * `parent::dispatch()`, never bind the interface fresh.
 */
class SaturationAwareSsrGateway implements Gateway
{
    /**
     * Generous on purpose: the bypass is the real load shedder, so this only needs
     * to catch an individual slow render without degrading a slow first render.
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

        // null is Inertia's CSR path
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
            // must surface, not be swallowed as CSR — keeps strict-HTTP-fake tests honest
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
