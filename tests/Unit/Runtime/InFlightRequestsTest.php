<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Codinglabs\Yolo\Runtime\InFlightRequests;

function inFlightOver(Repository $cache): InFlightRequests
{
    return new InFlightRequests($cache, 'task-1');
}

it('flushes the peak in-flight count then resets to what is live', function (): void {
    $cache = new Repository(new ArrayStore());
    $gauge = inFlightOver($cache);

    $gauge->enter();
    $gauge->enter();
    $gauge->enter(); // peak now 3
    $gauge->leave(); // 2 live, but the window peaked at 3

    expect($gauge->flushPeak())->toBe(3);

    // The window reset to the live count (2): a fresh window measures afresh.
    expect($gauge->flushPeak())->toBe(2);
});

it('records the high-water mark across interleaved enter/leave', function (): void {
    $cache = new Repository(new ArrayStore());
    $gauge = inFlightOver($cache);

    $gauge->enter();
    $gauge->leave();
    $gauge->enter();
    $gauge->enter(); // peaks at 2, never higher
    $gauge->leave();
    $gauge->leave();

    expect($gauge->flushPeak())->toBe(2);
});

it('reads zero in-flight at rest', function (): void {
    expect(inFlightOver(new Repository(new ArrayStore()))->flushPeak())->toBe(0);
});

it('tracks the live count through enter and leave', function (): void {
    $gauge = inFlightOver(new Repository(new ArrayStore()));

    expect($gauge->current())->toBe(0);

    $gauge->enter();
    $gauge->enter();
    expect($gauge->current())->toBe(2);

    $gauge->leave();
    expect($gauge->current())->toBe(1);
});

it('keeps task-scoped keys so a shared store does not collide', function (): void {
    $cache = new Repository(new ArrayStore());

    (new InFlightRequests($cache, 'task-a'))->enter();
    $taskB = new InFlightRequests($cache, 'task-b');

    expect($taskB->flushPeak())->toBe(0);
});
