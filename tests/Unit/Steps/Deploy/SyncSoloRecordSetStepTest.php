<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Deploy\SyncSoloRecordSetStep;

it('writes the primary record plus one plain alias per additional landlord host, each in its own zone', function (): void {
    $elb = [];
    bindAlbLookup($elb);

    $r53 = [];
    bindMockRoute53Client([
        ['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE-COM'],
        ['Name' => 'example.io.', 'Id' => '/hostedzone/ZONE-IO'],
    ], $r53);

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['example.com', 'app.example.io']]],
    ]);

    expect((new SyncSoloRecordSetStep())(['dry-run' => false]))->toBe(StepResult::SYNCED);

    $changeCalls = collect($r53)->where('name', 'ChangeResourceRecordSets')->values();

    expect($changeCalls)->toHaveCount(2);

    $byZone = $changeCalls->keyBy(fn (array $call): string => $call['args']['HostedZoneId']);

    // The primary (the apex itself) gets its own apex/www-pair treatment — two
    // records, both www and the bare apex.
    $primaryChanges = $byZone['ZONE-COM']['args']['ChangeBatch']['Changes'];
    expect(collect($primaryChanges)->pluck('ResourceRecordSet.Name')->all())
        ->toEqualCanonicalizing(['example.com', 'www.example.com']);

    // The additional host gets exactly one plain alias — no sibling, no wildcard.
    $additionalChanges = $byZone['ZONE-IO']['args']['ChangeBatch']['Changes'];
    expect($additionalChanges)->toHaveCount(1)
        ->and($additionalChanges[0]['ResourceRecordSet']['Name'])->toBe('app.example.io');
});

it('reports WOULD_SYNC on the plan pass without writing anything', function (): void {
    $elb = [];
    bindAlbLookup($elb);

    $r53 = [];
    bindMockRoute53Client([
        ['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE-COM'],
        ['Name' => 'example.io.', 'Id' => '/hostedzone/ZONE-IO'],
    ], $r53);

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['example.com', 'app.example.io']]],
    ]);

    expect((new SyncSoloRecordSetStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);

    expect(collect($r53)->where('name', 'ChangeResourceRecordSets'))->toBeEmpty();
});
