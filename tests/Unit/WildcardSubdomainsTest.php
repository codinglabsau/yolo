<?php

declare(strict_types=1);

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Resources\ElbV2\ForwardListenerRule;
use Codinglabs\Yolo\Resources\ElbV2\RedirectListenerRule;

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

describe('the apex/www redirect', function (): void {
    it('keeps the www sibling covered by the certificate when the domain is the apex', function (): void {
        // The wildcard moves the certificate from the apex to the domain, but for
        // an apex-canonical app those are the same name — so `*.example.com` still
        // covers the `www` host the redirect rule answers on.
        writeWildcardManifest(['domain' => 'example.com']);

        expect(Manifest::certificateDomain())->toBe('example.com')
            ->and(Manifest::wildcardHost())->toBe('*.example.com');
    });

    it('overlaps the redirect host, which the priority bands resolve', function (): void {
        writeWildcardManifest(['domain' => 'example.com']);

        $forward = (new ForwardListenerRule('arn:listener'))->hosts();
        $redirect = (new RedirectListenerRule('arn:listener'))->hosts();

        // `*.example.com` matches `www.example.com` too, so both rules match the
        // sibling and only the priority ordering decides which wins. The redirect
        // band sits below every forward rule so the 301 keeps firing.
        expect($forward)->toBe(['example.com', '*.example.com'])
            ->and($redirect)->toBe(['www.example.com'])
            ->and(ForwardListenerRule::nextAvailablePriority('yolo-testing-my-app', [], 10000, 49999))
            ->toBeGreaterThan(ForwardListenerRule::nextAvailablePriority('yolo-testing-my-app-redirect', [], 1000, 9999));
    });

    it('has no redirect to overlap for a bare subdomain', function (): void {
        // The common multi-tenant shape: no apex/www pair, so no redirect rule
        // exists and the wildcard has nothing to contend with.
        writeWildcardManifest();

        expect((new ForwardListenerRule('arn:listener'))->hosts())
            ->toBe(['app.example.com', '*.app.example.com'])
            ->and(recordSetSyncer()->hasWwwSibling('example.com', 'app.example.com'))->toBeFalse();
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
