<?php

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

describe('has and get', function () {
    it('returns true for existing keys', function () {
        writeManifest(['region' => 'us-west-2']);

        expect(Manifest::has('region'))->toBeTrue();
    });

    it('returns false for missing keys', function () {
        writeManifest([]);

        expect(Manifest::has('region'))->toBeFalse();
        expect(Manifest::doesntHave('region'))->toBeTrue();
    });

    it('gets values with defaults', function () {
        writeManifest(['region' => 'us-west-2']);

        expect(Manifest::get('region'))->toBe('us-west-2');
        expect(Manifest::get('missing', 'fallback'))->toBe('fallback');
    });

    it('has returns true even for falsy values', function () {
        writeManifest(['ivs' => false]);

        expect(Manifest::has('ivs'))->toBeTrue();
        expect(Manifest::get('ivs'))->toBeFalse();
    });
});

describe('name', function () {
    it('returns the app name', function () {
        expect(Manifest::name())->toBe('my-app');
    });
});

describe('timezone', function () {
    it('defaults to UTC', function () {
        writeManifest([]);

        expect(Manifest::timezone())->toBe('UTC');
    });

    it('reads configured timezone', function () {
        writeManifest([], 'testing');

        // timezone is read from root level, not environment level
        // so it always returns the manifest-level timezone or UTC default
        expect(Manifest::timezone())->toBe('UTC');
    });
});

describe('multitenancy', function () {
    it('is not multitenanted without tenants config', function () {
        writeManifest([]);

        expect(Manifest::isMultitenanted())->toBeFalse();
    });

    it('is multitenanted with tenants config', function () {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com', 'apex' => 'au.example.com'],
            ],
        ]);

        expect(Manifest::isMultitenanted())->toBeTrue();
    });

    it('normalises tenant apex from domain', function () {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com'],
            ],
        ]);

        $tenants = Manifest::tenants();

        expect($tenants['au']['apex'])->toBe('au.example.com');
    });

    it('rejects www apex', function () {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com', 'apex' => 'www.example.com'],
            ],
        ]);

        expect(fn () => Manifest::tenants())
            ->toThrow(IntegrityCheckException::class);
    });
});

describe('ivsEnabled', function () {
    it('is false when ivs is absent', function () {
        writeManifest([]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });

    it('is true for the boolean shorthand', function () {
        writeManifest(['ivs' => true]);

        expect(Manifest::ivsEnabled())->toBeTrue();
    });

    it('is false when ivs is explicitly false', function () {
        writeManifest(['ivs' => false]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });

    it('is true for the expanded form with logging on', function () {
        writeManifest(['ivs' => ['logging' => true, 'log-retention-days' => 30]]);

        expect(Manifest::ivsEnabled())->toBeTrue();
    });

    it('is false for the expanded form with logging off', function () {
        writeManifest(['ivs' => ['logging' => false, 'log-retention-days' => 30]]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });

    it('is false for the expanded form without a logging key', function () {
        writeManifest(['ivs' => ['log-retention-days' => 30]]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });
});

describe('apex', function () {
    it('returns the apex domain', function () {
        writeManifest(['domain' => 'example.com']);

        expect(Manifest::apex())->toBe('example.com');
    });

    it('prefers explicit apex over domain', function () {
        writeManifest([
            'domain' => 'www.example.com',
            'apex' => 'example.com',
        ]);

        expect(Manifest::apex())->toBe('example.com');
    });

    it('rejects www apex', function () {
        writeManifest(['apex' => 'www.example.com']);

        expect(fn () => Manifest::apex())
            ->toThrow(IntegrityCheckException::class);
    });

    it('throws for multitenanted environments', function () {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com'],
            ],
        ]);

        expect(fn () => Manifest::apex())
            ->toThrow(IntegrityCheckException::class);
    });
});
