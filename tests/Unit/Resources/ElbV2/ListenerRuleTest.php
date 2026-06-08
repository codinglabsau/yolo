<?php

declare(strict_types=1);

use Codinglabs\Yolo\Resources\ElbV2\ListenerRule;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('routes the apex and www hosts for an apex domain', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
    ]);

    expect(ListenerRule::routedHosts())->toBe(['codinglabs.com.au', 'www.codinglabs.com.au']);
});

it('routes only the subdomain when the domain is below the apex', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'apex' => 'codinglabs.com.au', 'domain' => 'app.codinglabs.com.au',
    ]);

    expect(ListenerRule::routedHosts())->toBe(['app.codinglabs.com.au']);
});

it('allocates a deterministic priority inside the ALB rule range', function (): void {
    $priority = ListenerRule::nextAvailablePriority('my-app', []);

    expect($priority)->toBeGreaterThanOrEqual(1000)
        ->toBeLessThanOrEqual(49999)
        // deterministic — the same app lands on the same priority across re-creates
        ->and(ListenerRule::nextAvailablePriority('my-app', []))->toBe($priority);
});

it('skips a priority already taken by another rule', function (): void {
    $base = ListenerRule::nextAvailablePriority('my-app', []);

    $next = ListenerRule::nextAvailablePriority('my-app', [$base]);

    expect($next)->not->toBe($base)
        ->toBeGreaterThanOrEqual(1000)
        ->toBeLessThanOrEqual(49999);
});
