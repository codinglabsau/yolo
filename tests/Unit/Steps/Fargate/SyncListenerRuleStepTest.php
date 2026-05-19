<?php

use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Steps\Fargate\SyncListenerRuleStep;

it('returns a priority within the 1000-49999 range', function () {
    $priority = SyncListenerRuleStep::nextAvailablePriority('my-app', []);

    expect($priority)->toBeGreaterThanOrEqual(1000)
        ->and($priority)->toBeLessThanOrEqual(49999);
});

it('returns a deterministic priority for the same name when no collisions', function () {
    $first = SyncListenerRuleStep::nextAvailablePriority('my-app', []);
    $second = SyncListenerRuleStep::nextAvailablePriority('my-app', []);

    expect($first)->toBe($second);
});

it('walks past collisions and stays within range', function () {
    $base = SyncListenerRuleStep::nextAvailablePriority('my-app', []);

    $next = SyncListenerRuleStep::nextAvailablePriority('my-app', [$base]);

    expect($next)->not->toBe($base)
        ->and($next)->toBeGreaterThanOrEqual(1000)
        ->and($next)->toBeLessThanOrEqual(49999);
});

it('wraps from the ceiling back to the floor on collision', function () {
    $used = range(49000, 49999);

    $priority = SyncListenerRuleStep::nextAvailablePriority('app-that-hashes-high', $used);

    expect($priority)->toBeLessThan(49000)
        ->and($priority)->toBeGreaterThanOrEqual(1000);
});

it('throws when the priority space is fully exhausted', function () {
    $used = range(1000, 49999);

    SyncListenerRuleStep::nextAvailablePriority('my-app', $used);
})->throws(IntegrityCheckException::class, 'priority space (1000-49999) exhausted');

it('never returns a priority below the 1000 floor', function () {
    // Walk every name that crc32-hashes near the ceiling and assert no floor escape.
    foreach (range(0, 50) as $i) {
        $priority = SyncListenerRuleStep::nextAvailablePriority("app-$i", []);
        expect($priority)->toBeGreaterThanOrEqual(1000);
    }
});
