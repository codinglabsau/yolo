<?php

declare(strict_types=1);

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Codinglabs\Yolo\Runtime\InFlightRequests;
use Codinglabs\Yolo\Runtime\Http\TrackInFlightRequests;

it('counts the request as in flight for the duration of the pipeline, then releases it', function (): void {
    $cache = new Repository(new ArrayStore());
    $gauge = new InFlightRequests($cache, 'task-1');
    $middleware = new TrackInFlightRequests($gauge);

    $seen = null;
    $middleware->handle(new Request(), function () use ($gauge, &$seen): Response {
        // Mid-pipeline — including any SSR render — the request is counted.
        $seen = $gauge->current();

        return new Response();
    });

    expect($seen)->toBe(1)
        // ...and released once the pipeline returns.
        ->and($gauge->current())->toBe(0);
});

it('releases the request even when the pipeline throws', function (): void {
    $cache = new Repository(new ArrayStore());
    $gauge = new InFlightRequests($cache, 'task-1');
    $middleware = new TrackInFlightRequests($gauge);

    $throw = fn (): Symfony\Component\HttpFoundation\Response => $middleware->handle(new Request(), function (): Response {
        throw new RuntimeException('boom');
    });

    expect($throw)->toThrow(RuntimeException::class);
    expect($gauge->current())->toBe(0);
});
