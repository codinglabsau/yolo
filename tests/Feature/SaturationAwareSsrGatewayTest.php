<?php

declare(strict_types=1);

use Tests\TestbenchCase;
use Inertia\Ssr\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;
use Codinglabs\Yolo\Runtime\Ssr\SaturationAwareSsrGateway;

uses(TestbenchCase::class);

/** A fresh array-backed cache (Pest helpers don't cross test files under --parallel). */
function gatewayCache(): Repository
{
    return new Repository(new ArrayStore());
}

/** A gateway that will dispatch in tests — render without a bundle on disk. */
function ssrGateway(Repository $cache): SaturationAwareSsrGateway
{
    config(['inertia.ssr.ensure_bundle_exists' => false]);

    return new SaturationAwareSsrGateway($cache, 'task-1');
}

it('dispatches to the SSR server and returns the rendered response when not saturated', function (): void {
    Http::fake(['*' => Http::response(['head' => ['<title>x</title>'], 'body' => '<main>hi</main>'])]);

    $response = ssrGateway(gatewayCache())->dispatch(['component' => 'Home']);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->body)->toBe('<main>hi</main>')
        ->and($response->head)->toBe('<title>x</title>');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/render'));
});

it('bypasses SSR and returns null without ever calling Node when the task is flagged saturated', function (): void {
    Http::fake();

    $cache = gatewayCache();
    $cache->put(WorkerSaturationReporter::ssrBypassKey('task-1'), 1, 60);

    expect(ssrGateway($cache)->dispatch(['component' => 'Home']))->toBeNull();

    Http::assertNothingSent();
});

it('falls back to CSR (null) when the SSR render errors', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(ssrGateway(gatewayCache())->dispatch(['component' => 'Home']))->toBeNull();
});

it('returns null without dispatching when SSR is disabled', function (): void {
    Http::fake();
    config(['inertia.ssr.enabled' => false]);

    expect((new SaturationAwareSsrGateway(gatewayCache(), 'task-1'))->dispatch(['component' => 'Home']))->toBeNull();

    Http::assertNothingSent();
});
