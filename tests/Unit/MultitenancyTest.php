<?php

declare(strict_types=1);

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\Tenant\SyncHostedZoneStep;
use Codinglabs\Yolo\Steps\Sync\App\Tenant\SyncForwardRuleStep;
use Codinglabs\Yolo\Steps\Sync\App\Tenant\SyncSslCertificateStep;
use Codinglabs\Yolo\Steps\Destroy\App\Tenant\TeardownForwardRuleStep;

beforeEach(fn () => bindHostedZones());

describe('the app host', function (): void {
    it('reads the root domain for a solo app', function (): void {
        writeManifest(['domain' => 'example.com']);

        expect(Manifest::domain())->toBe('example.com')
            ->and(Manifest::hasDomain())->toBeTrue();
    });

    it('reads the landlord domain for a tenanted app', function (): void {
        writeManifest(['multitenancy' => [
            'landlord' => ['domain' => 'app.example.com'],
            'tenants' => ['acme' => null],
        ]]);

        expect(Manifest::domain())->toBe('app.example.com')
            ->and(Manifest::isMultitenanted())->toBeTrue();
    });

    it('has no host when a tenanted app declares no landlord', function (): void {
        writeManifest(['multitenancy' => ['tenants' => ['acme' => ['domain' => 'acme.test']]]]);

        expect(Manifest::domain())->toBeNull()
            ->and(Manifest::hasDomain())->toBeFalse();
    });
});

describe('certificate coverage', function (): void {
    // The gate that lets `tenants` compose with `wildcard-subdomains` instead of
    // excluding it: a tenant the app's own certificate already covers provisions
    // no DNS/TLS resources of its own.
    it('decides whether the app already serves a host', function (string $host, bool $served): void {
        writeManifest(['multitenancy' => [
            'landlord' => ['domain' => 'app.example.com', 'wildcard-subdomains' => true],
            'tenants' => ['acme' => null],
        ]]);

        expect(Manifest::servesDomain($host))->toBe($served);
    })->with([
        'the landlord host itself' => ['app.example.com', true],
        'one label below it' => ['acme.app.example.com', true],
        'two labels below it' => ['a.b.app.example.com', false],  // ACM/ALB wildcards are one label deep
        'a genuine custom domain' => ['acme.com.au', false],
        'a lookalike suffix' => ['notapp.example.com', false],
    ]);

    it('serves only the exact host when the landlord has no wildcard', function (): void {
        writeManifest(['multitenancy' => [
            'landlord' => ['domain' => 'app.example.com'],
            'tenants' => ['acme' => null],
        ]]);

        expect(Manifest::servesDomain('app.example.com'))->toBeTrue()
            ->and(Manifest::servesDomain('acme.app.example.com'))->toBeFalse();
    });
});

describe('tenant config', function (): void {
    it('normalises a tenant declared bare', function (): void {
        writeManifest(['multitenancy' => ['tenants' => ['acme' => null]]]);

        expect(Manifest::tenants())->toBe(['acme' => []]);
    });

    it('derives the apex and pins the certificate to it', function (): void {
        writeManifest(['multitenancy' => ['tenants' => ['acme' => ['domain' => 'www.acme.com.au']]]]);

        expect(Manifest::tenants()['acme'])->toMatchArray([
            'domain' => 'www.acme.com.au',
            'apex' => 'acme.com.au',            // `www.` stripped — the apex is never the www host
            'certificate-domain' => 'acme.com.au',
            'wildcard-host' => null,
        ]);
    });

    it('moves a wildcarded tenant certificate onto its own domain', function (): void {
        // An apex certificate's `*.{apex}` does not reach `x.{sub}.{apex}`, so a
        // tenant serving its own subdomains needs the certificate one level deeper —
        // the same move the app-level flag makes.
        writeManifest(['multitenancy' => ['tenants' => [
            'acme' => ['domain' => 'portal.acme.com.au', 'wildcard-subdomains' => true],
        ]]]);

        expect(Manifest::tenants()['acme'])->toMatchArray([
            'certificate-domain' => 'portal.acme.com.au',
            'wildcard-host' => '*.portal.acme.com.au',
        ]);
    });

    it('reads declared tenant domains without touching AWS', function (): void {
        writeManifest(['multitenancy' => ['tenants' => [
            'acme' => ['domain' => 'acme.com.au'],
            'globex' => null,
        ]]]);

        expect(Manifest::tenantDomains())->toBe(['acme.com.au']);
    });
});

describe('per-tenant DNS/TLS steps', function (): void {
    // Every one of them self-skips for a tenant the app's certificate covers, so
    // declaring tenants purely for their queues costs no DNS/TLS resources.
    it('skips a tenant served under the landlord wildcard', function (string $step): void {
        writeManifest(['multitenancy' => [
            'landlord' => ['domain' => 'app.example.com', 'wildcard-subdomains' => true],
            'tenants' => ['acme' => ['domain' => 'acme.app.example.com']],
        ]]);

        $instance = (new $step())->setTenantId('acme')->setConfig(Manifest::tenants()['acme']);

        expect($instance(['dry-run' => true]))->toBe(StepResult::SKIPPED);
    })->with([
        SyncHostedZoneStep::class,
        SyncSslCertificateStep::class,
        SyncForwardRuleStep::class,
        TeardownForwardRuleStep::class,
    ]);

    it('skips a tenant with no domain of its own', function (string $step): void {
        writeManifest(['multitenancy' => [
            'landlord' => ['domain' => 'app.example.com', 'wildcard-subdomains' => true],
            'tenants' => ['acme' => null],
        ]]);

        $instance = (new $step())->setTenantId('acme')->setConfig(Manifest::tenants()['acme']);

        expect($instance(['dry-run' => true]))->toBe(StepResult::SKIPPED);
    })->with([
        SyncHostedZoneStep::class,
        SyncSslCertificateStep::class,
        SyncForwardRuleStep::class,
        TeardownForwardRuleStep::class,
    ]);
});
