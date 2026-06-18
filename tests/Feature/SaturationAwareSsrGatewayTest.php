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

it('renders via the SSR server and returns the response when not saturated', function (): void {
    Http::fake(['*' => Http::response(['head' => ['<title>x</title>'], 'body' => '<main>hi</main>'])]);

    $response = (new SaturationAwareSsrGateway(gatewayCache(), 'task-1'))->dispatch(['component' => 'Home']);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->body)->toBe('<main>hi</main>')
        ->and($response->head)->toBe('<title>x</title>');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/render'));
});

it('sheds to CSR (null) without ever calling Node when the task is flagged saturated', function (): void {
    Http::fake();

    $cache = gatewayCache();
    $cache->put(WorkerSaturationReporter::ssrBypassKey('task-1'), 1, 60);

    expect((new SaturationAwareSsrGateway($cache, 'task-1'))->dispatch(['component' => 'Home']))->toBeNull();

    Http::assertNothingSent();
});

it('falls back to CSR (null) when the render errors', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    expect((new SaturationAwareSsrGateway(gatewayCache(), 'task-1'))->dispatch(['component' => 'Home']))->toBeNull();
});

it('returns null without calling Node when SSR is disabled', function (): void {
    Http::fake();
    config(['inertia.ssr.enabled' => false]);

    expect((new SaturationAwareSsrGateway(gatewayCache(), 'task-1'))->dispatch(['component' => 'Home']))->toBeNull();

    Http::assertNothingSent();
});
