<?php

use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

beforeEach(function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);
});

it('defaults the drain to 10 seconds', function () {
    expect(ShutdownTimings::drain())->toBe(10);
});

it('drains for the manifest web shutdown-grace-period', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45]],
    ]);

    expect(ShutdownTimings::drain())->toBe(45);
});

it('skips the drain entirely when headless (no ALB to drain)', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45]],
    ]);

    expect(ShutdownTimings::drain())->toBe(0);
});

it('runs only octane by default, inheriting the drain for its stop window', function () {
    expect(ShutdownTimings::programGraces())->toBe(['octane' => 10]);
});

it('octane and scheduler inherit the web shutdown-grace-period', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 20, 'scheduler' => true]],
    ]);

    expect(ShutdownTimings::programGraces())->toBe(['octane' => 20, 'scheduler' => 20]);
});

it('gives the queue worker a longer default than the web tier', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => true]],
    ]);

    expect(ShutdownTimings::programGraces())->toBe(['octane' => 10, 'queue' => 70]);
});

it('honours a queue shutdown-grace-period override via the object form', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => ['shutdown-grace-period' => 90]]],
    ]);

    expect(ShutdownTimings::programGraces())->toBe(['octane' => 10, 'queue' => 90]);
});

it('treats the queue object form as enabled even without an explicit flag', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => ['shutdown-grace-period' => 30], 'scheduler' => false]],
    ]);

    $graces = ShutdownTimings::programGraces();

    expect($graces)->toHaveKey('queue');
    expect($graces)->not->toHaveKey('scheduler');
});

it('derives the stop timeout from the drain plus the slowest program', function () {
    // octane only: drain 10 + max(octane 10) + 5 buffer.
    expect(ShutdownTimings::stopTimeout())->toBe(25);
});

it('sizes the stop timeout around a long queue grace', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => true]],
    ]);

    // drain 10 + max(octane 10, queue 70) + 5 buffer.
    expect(ShutdownTimings::stopTimeout())->toBe(85);
});

it('drops the drain from the stop timeout when headless', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => true]],
    ]);

    // no drain + max(octane 10, queue 70) + 5 buffer.
    expect(ShutdownTimings::stopTimeout())->toBe(75);
});

it('caps the stop timeout at the Fargate maximum of 120s', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => ['shutdown-grace-period' => 300]]],
    ]);

    expect(ShutdownTimings::stopTimeout())->toBe(120);
});

it('rejects a non-boolean, non-object program flag', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => 'sometimes']],
    ]);

    expect(fn () => ShutdownTimings::programGraces())
        ->toThrow(IntegrityCheckException::class);
});
