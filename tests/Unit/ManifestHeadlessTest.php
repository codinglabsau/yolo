<?php

declare(strict_types=1);

use Codinglabs\Yolo\Manifest;

describe('isHeadless', function (): void {
    it('is true for a solo manifest with no domain', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        ]);

        expect(Manifest::isHeadless())->toBeTrue();
    });

    it('is false for a solo manifest with a domain', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'example.com',
        ]);

        expect(Manifest::isHeadless())->toBeFalse();
    });

    it('is true when every tenant lacks a domain', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tenants' => [
                'worker-a' => [],
                'worker-b' => [],
            ],
        ]);

        expect(Manifest::isHeadless())->toBeTrue();
    });

    it('is false when at least one tenant declares a domain', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tenants' => [
                'worker-a' => [],
                'site-b' => ['domain' => 'b.example.com'],
            ],
        ]);

        expect(Manifest::isHeadless())->toBeFalse();
    });

});

describe('tenants() normalisation', function (): void {
    it('does not TypeError on a headless tenant entry', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tenants' => [
                'worker-a' => [],
            ],
        ]);

        $tenants = Manifest::tenants();

        expect($tenants['worker-a'])->not->toHaveKey('apex');
    });

    it('still resolves apex from domain when only domain is set', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tenants' => [
                'site-a' => ['domain' => 'a.example.com'],
            ],
        ]);

        bindHostedZones();

        expect(Manifest::tenants()['site-a']['apex'])->toBe('a.example.com');
    });
});
