<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\Solo\SyncHostedZoneStep;

it('syncs only the primary apex zone for a single-host landlord', function (): void {
    $captured = [];
    bindMockRoute53Client([
        ['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE-COM'],
    ], $captured);

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => 'app.example.com']],
    ]);

    expect((new SyncHostedZoneStep())(['dry-run' => false]))->toBe(StepResult::SYNCED);

    // Only ONE zone touched — no ListTagsForResource/ChangeTagsForResource pair
    // for a second, non-existent apex.
    expect(collect($captured)->where('name', 'ChangeTagsForResource')->count())->toBe(1);
});

it('syncs a zone per distinct apex when the landlord spans several domains', function (): void {
    $captured = [];
    bindMockRoute53Client([
        ['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE-COM'],
        ['Name' => 'example.io.', 'Id' => '/hostedzone/ZONE-IO'],
    ], $captured);

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['app.example.com', 'app.example.io']]],
    ]);

    expect((new SyncHostedZoneStep())(['dry-run' => false]))->toBe(StepResult::SYNCED);

    $tagCalls = collect($captured)->where('name', 'ChangeTagsForResource')->values();

    expect($tagCalls)->toHaveCount(2)
        ->and($tagCalls->pluck('args.ResourceId')->all())->toEqualCanonicalizing(['ZONE-COM', 'ZONE-IO']);
});

it('dedupes when two additional hosts share the same apex', function (): void {
    $captured = [];
    bindMockRoute53Client([
        ['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE-COM'],
    ], $captured);

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['app.example.com', 'brand.example.com']]],
    ]);

    expect((new SyncHostedZoneStep())(['dry-run' => false]))->toBe(StepResult::SYNCED);

    expect(collect($captured)->where('name', 'ChangeTagsForResource')->count())->toBe(1);
});
