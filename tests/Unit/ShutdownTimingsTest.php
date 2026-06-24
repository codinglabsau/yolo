<?php

declare(strict_types=1);

use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

beforeEach(function (): void {
    // A plain web app: the web container bundles all three roles (web + queue +
    // scheduler), since neither queue nor scheduler is extracted.
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
});

it('defaults the drain to 15 seconds', function (): void {
    expect(ShutdownTimings::drain())->toBe(15);
});

it('drains for the manifest web shutdown-grace-period', function (): void {
    writeManifest([
        'domain' => 'example.com',
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

it('bundles the web server, scheduler and queue worker into the web container for a plain web app', function (): void {
    // The scheduler's grace defaults to the whole stop window (Fargate's 120s
    // cap minus the 5s buffer) — its stop overlaps the other programs'.
    expect(ShutdownTimings::programGraces())->toBe(['web' => 15, 'scheduler' => 115, 'queue' => 60]);
});

it('runs the web server alone in the web container when both queue and scheduler are extracted', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['web' => 15]);
});

it('tracks the web shutdown-grace-period for the web server; the bundled scheduler keeps its own default', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 20]],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['web' => 20, 'scheduler' => 115, 'queue' => 60]);
});

it('runs the scheduler and queue worker together in a standalone queue container', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
    ]);

    // Queue extracted, scheduler not — so the scheduler rides the queue container,
    // and the web container is left with the web server alone.
    expect(ShutdownTimings::programGraces(ServerGroup::QUEUE))->toBe(['scheduler' => 115, 'queue' => 60]);
    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['web' => 15]);
});

it('runs the queue worker alone in its container when the scheduler is its own service', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::QUEUE))->toBe(['queue' => 60]);
    expect(ShutdownTimings::programGraces(ServerGroup::SCHEDULER))->toBe(['scheduler' => 115]);
});

it('honours a standalone scheduler shutdown-grace-period override', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true, 'scheduler' => ['shutdown-grace-period' => 30]],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::SCHEDULER))->toBe(['scheduler' => 30]);
    expect(ShutdownTimings::stopTimeoutFor(ServerGroup::SCHEDULER))->toBe(35);
});

it('honours a standalone queue shutdown-grace-period override', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['shutdown-grace-period' => 90], 'scheduler' => true],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::QUEUE))->toBe(['queue' => 90]);
});

it('bundles the ssr renderer into the web container when tasks.web.ssr is on', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true]],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['web' => 15, 'ssr' => 5, 'scheduler' => 115, 'queue' => 60]);
});

it('honours an ssr shutdown-grace-period override via the object form', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => ['shutdown-grace-period' => 12]]],
    ]);

    expect(ShutdownTimings::programGraces(ServerGroup::WEB))->toBe(['web' => 15, 'ssr' => 12, 'scheduler' => 115, 'queue' => 60]);
});

it('sizes the web stop timeout as the slower of the drain track and the scheduler grace', function (): void {
    // The scheduler's stop overlaps everything else: the budget is
    // max(drain 15 + slowest other program 60, scheduler 115) + 5 buffer —
    // never the sum, so the in-flight schedule:run keeps the whole window.
    expect(ShutdownTimings::stopTimeoutFor(ServerGroup::WEB))->toBe(120);
});

it('drops the ALB drain window from the web stop timeout when headless', function (): void {
    // Scheduler extracted so the drain track is what sizes the budget.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'scheduler' => true],
    ]);

    // no drain window: 0 + max(web 15, queue 60) + 5 buffer.
    expect(ShutdownTimings::stopTimeoutFor(ServerGroup::WEB))->toBe(65);
});

it('allows graces that exactly fill the Fargate stop ceiling', function (): void {
    // The scheduler track (115 + 5) lands at exactly 120; the drain track
    // (drain 45 + queue 60 + 5 = 110) sits under it — the default scheduler
    // grace never overcommits on its own.
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45]],
    ]);

    expect(ShutdownTimings::stopTimeoutFor(ServerGroup::WEB))->toBe(120);
});

it('rejects graces that overcommit the Fargate stop ceiling instead of silently clamping', function (): void {
    // drain 60 + queue 60 + buffer 5 = 125 > 120: clamping would let supervisord
    // promise the queue a window ECS cuts off at the wire.
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 60]],
    ]);

    expect(fn (): int => ShutdownTimings::stopTimeoutFor(ServerGroup::WEB))
        ->toThrow(IntegrityCheckException::class, 'Fargate caps it at 120s');
});

it('rejects a non-boolean, non-object ssr flag', function (): void {
    writeManifest([
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
            'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
        ]);

        // 60 (default queue grace) + 5 buffer; no ALB drain, no co-hosted scheduler.
        expect(ShutdownTimings::stopTimeoutFor(ServerGroup::QUEUE))->toBe(65);
    });

    it('sizes a queue+scheduler stop timeout as the slower of the two overlapped stops', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true, 'queue' => true],
        ]);

        // supervisord signals queue:work and supercronic together; the budget is
        // max(queue 60, scheduler 115) + 5 buffer.
        expect(ShutdownTimings::stopTimeoutFor(ServerGroup::QUEUE))->toBe(120);
    });

    it('sizes a scheduler-only stop timeout as its grace plus buffer', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
        ]);

        expect(ShutdownTimings::stopTimeoutFor(ServerGroup::SCHEDULER))->toBe(120);
    });

    it('rejects a standalone grace that overcommits the Fargate stop ceiling', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true, 'queue' => ['shutdown-grace-period' => 200], 'scheduler' => true],
        ]);

        expect(fn (): int => ShutdownTimings::stopTimeoutFor(ServerGroup::QUEUE))
            ->toThrow(IntegrityCheckException::class);
    });
});
