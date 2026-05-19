<?php

use Codinglabs\Yolo\Manifest;

describe('isHeadless', function () {
    it('is true for a solo manifest with no domain and no apex', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        ]);

        expect(Manifest::isHeadless())->toBeTrue();
    });

    it('is false for a solo manifest with a domain', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'domain' => 'codinglabs.com.au',
        ]);

        expect(Manifest::isHeadless())->toBeFalse();
    });

    it('is false for a solo manifest with an apex', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'apex' => 'codinglabs.com.au',
        ]);

        expect(Manifest::isHeadless())->toBeFalse();
    });

    it('is true when every tenant lacks both apex and domain', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tenants' => [
                'worker-a' => [],
                'worker-b' => [],
            ],
        ]);

        expect(Manifest::isHeadless())->toBeTrue();
    });

    it('is false when at least one tenant declares a domain', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tenants' => [
                'worker-a' => [],
                'site-b' => ['domain' => 'b.example.com'],
            ],
        ]);

        expect(Manifest::isHeadless())->toBeFalse();
    });

    it('is false when at least one tenant declares an apex', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tenants' => [
                'site-a' => ['apex' => 'a.example.com'],
            ],
        ]);

        expect(Manifest::isHeadless())->toBeFalse();
    });
});

describe('tenants() normalisation', function () {
    it('does not TypeError on a headless tenant entry', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tenants' => [
                'worker-a' => [],
            ],
        ]);

        $tenants = Manifest::tenants();

        expect($tenants['worker-a']['apex'])->toBeNull();
    });

    it('still resolves apex from domain when only domain is set', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tenants' => [
                'site-a' => ['domain' => 'a.example.com'],
            ],
        ]);

        expect(Manifest::tenants()['site-a']['apex'])->toBe('a.example.com');
    });
});
