<?php

use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Steps\Fargate\SyncListenerRuleStep;

describe('routedHosts', function () {
    it('routes apex + www.apex when domain matches apex', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'domain' => 'codinglabs.com.au',
        ]);

        expect(SyncListenerRuleStep::routedHosts())
            ->toBe(['codinglabs.com.au', 'www.codinglabs.com.au']);
    });

    it('routes apex + www.apex when only apex is set', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'apex' => 'codinglabs.com.au',
        ]);

        expect(SyncListenerRuleStep::routedHosts())
            ->toBe(['codinglabs.com.au', 'www.codinglabs.com.au']);
    });

    it('routes only the literal domain when apex and domain differ', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'apex' => 'codinglabs.com.au',
            'domain' => 'fargate.codinglabs.com.au',
        ]);

        expect(SyncListenerRuleStep::routedHosts())
            ->toBe(['fargate.codinglabs.com.au']);
    });

    it('routes only the literal domain for tenant-style subdomains (apex ≠ domain)', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'apex' => 'liveplatforms.net',
            'domain' => 'brushly.liveplatforms.net',
        ]);

        expect(SyncListenerRuleStep::routedHosts())
            ->toBe(['brushly.liveplatforms.net']);
    });
});

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
