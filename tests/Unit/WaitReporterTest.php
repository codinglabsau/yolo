<?php

use Codinglabs\Yolo\WaitReporter;

afterEach(fn () => WaitReporter::clear());

it('is a no-op when no reporter is registered', function () {
    // Nothing registered → poll() must not throw (the common case: a waiter
    // running outside a LongRunning step).
    WaitReporter::poll();

    expect(true)->toBeTrue();
});

it('invokes the registered reporter on poll', function () {
    $calls = 0;
    WaitReporter::using(function () use (&$calls) {
        $calls++;
    });

    WaitReporter::poll();
    WaitReporter::poll();

    expect($calls)->toBe(2);
});

it('stops calling the reporter once cleared', function () {
    $calls = 0;
    WaitReporter::using(function () use (&$calls) {
        $calls++;
    });

    WaitReporter::poll();
    WaitReporter::clear();
    WaitReporter::poll();

    expect($calls)->toBe(1);
});
