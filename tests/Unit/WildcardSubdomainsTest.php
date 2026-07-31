<?php

declare(strict_types=1);

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Route53\HostedZone;

/**
 * `wildcard-subdomains` serves every single-label subdomain of the app's own
 * `domain` from the one service — one listener-rule host and one alias record
 * instead of a resource per subdomain. These pin the three places that shifts:
 * which name the certificate is issued for, which hosts get alias records, and
 * that teardown still recognises the wildcard record on the way back out.
 */
function writeWildcardManifest(array $extra = []): void
{
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'app.example.com',
        'wildcard-subdomains' => true,
        ...$extra,
    ]);
}

beforeEach(function (): void {
    bindHostedZones(['example.com']);
});

describe('manifest', function (): void {
    it('is off unless declared', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'domain' => 'app.example.com']);

        expect(Manifest::servesWildcardSubdomains())->toBeFalse()
            ->and(Manifest::wildcardHost())->toBeNull();
    });

    it('resolves the wildcard one label below the app domain', function (): void {
        writeWildcardManifest();

        expect(Manifest::wildcardHost())->toBe('*.app.example.com');
    });

    it('issues the certificate for the apex when off', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'domain' => 'app.example.com']);

        expect(Manifest::certificateDomain())->toBe('example.com');
    });

    it('issues the certificate for the domain when on, so the wildcard reaches one label deeper', function (): void {
        // The apex certificate's `*.example.com` matches `app.example.com` but not
        // `tenant.app.example.com` — ACM wildcards match a single label — so a
        // wildcard-subdomain app needs its certificate issued a level down.
        writeWildcardManifest();

        expect(Manifest::certificateDomain())->toBe('app.example.com')
            ->and(Manifest::apex())->toBe('example.com');
    });
});

describe('dns records', function (): void {
    it('upserts the wildcard alongside the canonical host', function (): void {
        $elb = [];
        bindAlbLookup($elb);

        $r53 = [];
        bindMockRoute53Client([['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE1']], $r53);

        writeWildcardManifest();

        recordSetSyncer()->syncRecordSet('example.com', 'app.example.com');

        $change = collect($r53)->firstWhere('name', 'ChangeResourceRecordSets');

        expect(collect($change['args']['ChangeBatch']['Changes'])->pluck('ResourceRecordSet.Name')->all())
            ->toBe(['app.example.com', '*.app.example.com'])
            // both land in the existing apex zone — no zone of the domain's own
            ->and($change['args']['HostedZoneId'])->toBe('ZONE1');
    });

    it('adds the wildcard to the apex/www pair when the domain is the apex', function (): void {
        $elb = [];
        bindAlbLookup($elb);

        $r53 = [];
        bindMockRoute53Client([['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE1']], $r53);

        writeWildcardManifest(['domain' => 'example.com']);

        recordSetSyncer()->syncRecordSet('example.com', 'example.com');

        expect(collect(collect($r53)->firstWhere('name', 'ChangeResourceRecordSets')['args']['ChangeBatch']['Changes'])
            ->pluck('ResourceRecordSet.Name')->all())
            ->toEqualCanonicalizing(['example.com', 'www.example.com', '*.example.com']);
    });
});

describe('teardown', function (): void {
    it('recognises the wildcard record through Route 53\'s octal escaping', function (): void {
        // Route 53 stores a `*` label as `\052` and returns it that way on read, so
        // a raw string comparison would miss the record YOLO itself wrote and leave
        // the wildcard behind on destroy:app.
        $captured = [];
        bindMockRoute53Client(
            [['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE1']],
            $captured,
            [
                ['Type' => 'A', 'Name' => 'app.example.com.'],
                ['Type' => 'A', 'Name' => '\\052.app.example.com.'],
                // a sibling app's record, and the zone's own mail — neither is ours
                ['Type' => 'A', 'Name' => 'other.example.com.'],
                ['Type' => 'MX', 'Name' => 'example.com.'],
            ],
        );

        writeWildcardManifest();

        expect(collect((new HostedZone('example.com'))->appRecords())->pluck('Name')->all())
            ->toBe(['app.example.com', '*.app.example.com']);
    });
});
