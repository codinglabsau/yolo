<?php

declare(strict_types=1);

use Codinglabs\Yolo\Steps\Sync\App\SyncTypesenseKeyStep;

it('returns true on the first healthy check, without waiting', function (): void {
    $waits = 0;

    $healthy = SyncTypesenseKeyStep::pollHealthy(
        isHealthy: fn (): bool => true,
        attempts: 60,
        betweenAttempts: function () use (&$waits): void {
            $waits++;
        },
    );

    expect($healthy)->toBeTrue();
    expect($waits)->toBe(0);
});

it('polls until the endpoint becomes healthy, then succeeds', function (): void {
    $checks = 0;
    $waits = 0;

    $healthy = SyncTypesenseKeyStep::pollHealthy(
        // healthy only on the third check
        isHealthy: function () use (&$checks): bool {
            return ++$checks >= 3;
        },
        attempts: 60,
        betweenAttempts: function () use (&$waits): void {
            $waits++;
        },
    );

    expect($healthy)->toBeTrue();
    expect($checks)->toBe(3);
    // waited after the two failed checks, not after the successful one
    expect($waits)->toBe(2);
});

it('gives up after the bounded attempts instead of hanging', function (): void {
    $checks = 0;
    $waits = 0;

    $healthy = SyncTypesenseKeyStep::pollHealthy(
        isHealthy: function () use (&$checks): bool {
            $checks++;

            return false;
        },
        attempts: 5,
        betweenAttempts: function () use (&$waits): void {
            $waits++;
        },
    );

    expect($healthy)->toBeFalse();
    // checked exactly `attempts` times, waited between but never after the last
    expect($checks)->toBe(5);
    expect($waits)->toBe(4);
});
