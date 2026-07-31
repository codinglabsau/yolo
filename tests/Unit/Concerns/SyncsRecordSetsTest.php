<?php

declare(strict_types=1);

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('upserts both the apex and www alias records for an apex domain', function (): void {
    $elb = [];
    bindAlbLookup($elb);

    $r53 = [];
    bindMockRoute53Client([['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE1']], $r53);

    recordSetSyncer()->syncRecordSet('example.com', 'example.com');

    $change = collect($r53)->firstWhere('name', 'ChangeResourceRecordSets');
    $changes = $change['args']['ChangeBatch']['Changes'];

    expect($changes)->toHaveCount(2)
        ->and($changes[0]['Action'])->toBe('UPSERT')
        ->and($changes[0]['ResourceRecordSet']['Name'])->toBe('example.com')
        ->and($changes[0]['ResourceRecordSet']['Type'])->toBe('A')
        ->and($changes[0]['ResourceRecordSet']['AliasTarget']['DNSName'])->toBe('alb-1.ap-southeast-2.elb.amazonaws.com')
        ->and($changes[0]['ResourceRecordSet']['AliasTarget']['HostedZoneId'])->toBe('ZALB123')
        ->and($changes[1]['ResourceRecordSet']['Name'])->toBe('www.example.com')
        // the Route 53 SDK's CleanId middleware strips the /hostedzone/ prefix before the call
        ->and($change['args']['HostedZoneId'])->toBe('ZONE1');
});

it('upserts both records for a www-canonical domain (www served, apex redirects)', function (): void {
    $elb = [];
    bindAlbLookup($elb);

    $r53 = [];
    bindMockRoute53Client([['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE1']], $r53);

    recordSetSyncer()->syncRecordSet('example.com', 'www.example.com');

    $changes = collect($r53)->firstWhere('name', 'ChangeResourceRecordSets')['args']['ChangeBatch']['Changes'];

    // Both halves resolve to the ALB; the canonical (www) is served and the apex
    // sibling is 301-redirected by the listener rule.
    expect($changes)->toHaveCount(2)
        ->and(collect($changes)->pluck('ResourceRecordSet.Name')->all())
        ->toEqualCanonicalizing(['www.example.com', 'example.com']);
});

it('upserts a single alias record for a subdomain', function (): void {
    $elb = [];
    bindAlbLookup($elb);

    $r53 = [];
    bindMockRoute53Client([['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE1']], $r53);

    recordSetSyncer()->syncRecordSet('example.com', 'app.example.com');

    $changes = collect($r53)->firstWhere('name', 'ChangeResourceRecordSets')['args']['ChangeBatch']['Changes'];

    expect($changes)->toHaveCount(1)
        ->and($changes[0]['ResourceRecordSet']['Name'])->toBe('app.example.com')
        ->and($changes[0]['ResourceRecordSet']['AliasTarget']['DNSName'])->toBe('alb-1.ap-southeast-2.elb.amazonaws.com');
});
