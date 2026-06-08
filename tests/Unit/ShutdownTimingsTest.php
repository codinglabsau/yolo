<?php

declare(strict_types=1);

use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

beforeEach(function (): void {
    // A plain web app: the web container bundles all three roles (octane + queue +
    // scheduler), since neither queue nor scheduler is extracted.
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);
});

it('defaults the drain to 10 seconds', function (): void {
    expect(ShutdownTimings::drain())->toBe(10);
});

it('drains for the manifest web shutdown-grace-period', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45]],
    ]);

    expect(ShutdownTimings::drain())->toBe(45);
});

it('skips the drain entirely when headless (no ALB to drain)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45]],
    ]);

    expect(ShutdownTimings::drain())->toBe(0);
});

it('bundles octane, scheduler and queue into the web container for a plain web app', function (): void {
    expect(ShutdownTimings::programGraces())->toBe(['octane' => 10, 'scheduler' => 10, 'queue' => 70]);
});

it('runs octane alone in the web container when both queue and scheduler are extracted', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['octane' => 10]);
});

it('tracks the web shutdown-grace-period for octane; the bundled scheduler keeps its own default', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 20]],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['octane' => 20, 'scheduler' => 10, 'queue' => 70]);
});

it('runs the scheduler and queue worker together in a standalone queue container', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    // Queue extracted, scheduler not — so the scheduler rides the queue container,
    // and the web container is left with octane alone.
    expect(ShutdownTimings::programGraces(ServerGroup::QUEUE))->toBe(['scheduler' => 10, 'queue' => 70]);
    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['octane' => 10]);
});

it('runs the queue worker alone in its container when the scheduler is its own service', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::QUEUE))->toBe(['queue' => 70]);
    expect(ShutdownTimings::programGraces(ServerGroup::SCHEDULER))->toBe(['scheduler' => 10]);
});

it('honours a standalone queue shutdown-grace-period override', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => ['shutdown-grace-period' => 90], 'scheduler' => []],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::QUEUE))->toBe(['queue' => 90]);
});

it('bundles the ssr renderer into the web container when tasks.web.ssr is on', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true]],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['octane' => 10, 'ssr' => 5, 'scheduler' => 10, 'queue' => 70]);
});

it('honours an ssr shutdown-grace-period override via the object form', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => ['shutdown-grace-period' => 12]]],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['octane' => 10, 'ssr' => 12, 'scheduler' => 10, 'queue' => 70]);
});

it('sizes the web stop timeout around the drain, the scheduler wait and the slowest bundled program', function (): void {
    // scheduler drains first within max(drain 10, scheduler 10), then supervisord
    // stops octane + queue in parallel for max(10, 70); plus the 5s buffer.
    expect(ShutdownTimings::stopTimeoutFor(ServerGroup::WEB))->toBe(85);
});

it('drops the ALB drain window from the web stop timeout when headless', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    // no drain window: max(0, scheduler 10) + max(octane 10, queue 70) + 5 buffer.
    expect(ShutdownTimings::stopTimeoutFor(ServerGroup::WEB))->toBe(85);
});

it('caps the web stop timeout at the Fargate maximum of 120s', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 300]],
    ]);

    expect(ShutdownTimings::stopTimeoutFor(ServerGroup::WEB))->toBe(120);
});

it('rejects a non-boolean, non-object ssr flag', function (): void {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => 'sometimes']],
    ]);

    expect(fn (): array => ShutdownTimings::programGraces(ServerGroup::WEB))
        ->toThrow(IntegrityCheckException::class);
});

describe('standalone services', function (): void {
    it('sizes a queue-only stop timeout as the grace plus buffer (no ALB drain, no scheduler)', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
        ]);

        // 70 (default queue grace) + 5 buffer; no ALB drain, no co-hosted scheduler.
        expect(ShutdownTimings::stopTimeoutFor(ServerGroup::QUEUE))->toBe(75);
    });

    it('sizes a queue+scheduler stop timeout as the scheduler wait plus the queue grace', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => [], 'queue' => []],
        ]);

        // scheduler drains first (max(0, 10)), then the queue worker stops (70); plus buffer.
        expect(ShutdownTimings::stopTimeoutFor(ServerGroup::QUEUE))->toBe(85);
    });

    it('sizes a scheduler-only stop timeout as its grace plus buffer', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
        ]);

        expect(ShutdownTimings::stopTimeoutFor(ServerGroup::SCHEDULER))->toBe(15);
    });

    it('caps a standalone stop timeout at the Fargate maximum', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => [], 'queue' => ['shutdown-grace-period' => 200], 'scheduler' => []],
        ]);

        expect(ShutdownTimings::stopTimeoutFor(ServerGroup::QUEUE))->toBe(120);
    });
});
