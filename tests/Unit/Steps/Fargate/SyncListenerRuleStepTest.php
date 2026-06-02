<?php

use Codinglabs\Yolo\Resources\ElbV2\ListenerRule;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

describe('routedHosts', function () {
    it('routes apex + www.apex when domain matches apex', function () {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'codinglabs.com.au',
        ]);

        expect(ListenerRule::routedHosts())
            ->toBe(['codinglabs.com.au', 'www.codinglabs.com.au']);
    });

    it('routes apex + www.apex when only apex is set', function () {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'apex' => 'codinglabs.com.au',
        ]);

        expect(ListenerRule::routedHosts())
            ->toBe(['codinglabs.com.au', 'www.codinglabs.com.au']);
    });

    it('routes only the literal domain when apex and domain differ', function () {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'apex' => 'codinglabs.com.au',
            'domain' => 'fargate.codinglabs.com.au',
        ]);

        expect(ListenerRule::routedHosts())
            ->toBe(['fargate.codinglabs.com.au']);
    });

    it('routes only the literal domain for tenant-style subdomains (apex ≠ domain)', function () {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'apex' => 'liveplatforms.net',
            'domain' => 'brushly.liveplatforms.net',
        ]);

        expect(ListenerRule::routedHosts())
            ->toBe(['brushly.liveplatforms.net']);
    });
});

it('returns a priority within the 1000-49999 range', function () {
    $priority = ListenerRule::nextAvailablePriority('my-app', []);

    expect($priority)->toBeGreaterThanOrEqual(1000)
        ->and($priority)->toBeLessThanOrEqual(49999);
});

it('returns a deterministic priority for the same name when no collisions', function () {
    $first = ListenerRule::nextAvailablePriority('my-app', []);
    $second = ListenerRule::nextAvailablePriority('my-app', []);

    expect($first)->toBe($second);
});

it('walks past collisions and stays within range', function () {
    $base = ListenerRule::nextAvailablePriority('my-app', []);

    $next = ListenerRule::nextAvailablePriority('my-app', [$base]);

    expect($next)->not->toBe($base)
        ->and($next)->toBeGreaterThanOrEqual(1000)
        ->and($next)->toBeLessThanOrEqual(49999);
});

it('wraps from the ceiling back to the floor on collision', function () {
    $used = range(49000, 49999);

    $priority = ListenerRule::nextAvailablePriority('app-that-hashes-high', $used);

    expect($priority)->toBeLessThan(49000)
        ->and($priority)->toBeGreaterThanOrEqual(1000);
});

it('throws when the priority space is fully exhausted', function () {
    $used = range(1000, 49999);

    ListenerRule::nextAvailablePriority('my-app', $used);
})->throws(IntegrityCheckException::class, 'priority space (1000-49999) exhausted');

it('never returns a priority below the 1000 floor', function () {
    // Walk every name that crc32-hashes near the ceiling and assert no floor escape.
    foreach (range(0, 50) as $i) {
        $priority = ListenerRule::nextAvailablePriority("app-$i", []);
        expect($priority)->toBeGreaterThanOrEqual(1000);
    }
});
