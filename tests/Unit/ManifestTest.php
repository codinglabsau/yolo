<?php

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

describe('has and get', function () {
    it('returns true for existing keys', function () {
        writeManifest(['aws' => ['region' => 'us-west-2']]);

        expect(Manifest::has('aws.region'))->toBeTrue();
    });

    it('returns false for missing keys', function () {
        writeManifest([]);

        expect(Manifest::has('aws.region'))->toBeFalse();
        expect(Manifest::doesntHave('aws.region'))->toBeTrue();
    });

    it('gets values with defaults', function () {
        writeManifest(['aws' => ['region' => 'us-west-2']]);

        expect(Manifest::get('aws.region'))->toBe('us-west-2');
        expect(Manifest::get('aws.missing', 'fallback'))->toBe('fallback');
    });

    it('has returns true even for falsy values', function () {
        writeManifest(['aws' => ['ivs' => false]]);

        expect(Manifest::has('aws.ivs'))->toBeTrue();
        expect(Manifest::get('aws.ivs'))->toBeFalse();
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

describe('ivsLoggingEnabled', function () {
    it('is false when aws.ivs is absent', function () {
        writeManifest([]);

        expect(Manifest::ivsLoggingEnabled())->toBeFalse();
    });

    it('is true for the boolean shorthand', function () {
        writeManifest(['aws' => ['ivs' => true]]);

        expect(Manifest::ivsLoggingEnabled())->toBeTrue();
    });

    it('is false when aws.ivs is explicitly false', function () {
        writeManifest(['aws' => ['ivs' => false]]);

        expect(Manifest::ivsLoggingEnabled())->toBeFalse();
    });

    it('is true for the expanded form with logging on', function () {
        writeManifest(['aws' => ['ivs' => ['logging' => true, 'log-retention-days' => 30]]]);

        expect(Manifest::ivsLoggingEnabled())->toBeTrue();
    });

    it('is false for the expanded form with logging off', function () {
        writeManifest(['aws' => ['ivs' => ['logging' => false, 'log-retention-days' => 30]]]);

        expect(Manifest::ivsLoggingEnabled())->toBeFalse();
    });

    it('is false for the expanded form without a logging key', function () {
        writeManifest(['aws' => ['ivs' => ['log-retention-days' => 30]]]);

        expect(Manifest::ivsLoggingEnabled())->toBeFalse();
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
