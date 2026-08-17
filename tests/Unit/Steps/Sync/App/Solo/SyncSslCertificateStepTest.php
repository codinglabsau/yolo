<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\Solo\SyncSslCertificateStep;

// The primary landlord host IS the apex here (its own zone, no wildcard-subdomains),
// so the certificate is issued for `example.com` + `*.example.com` — the step
// derives the app's apex before it ever inspects the certificate, so every case
// needs a Route 53 zone list even when it never touches Route 53 otherwise (the
// ISSUED-and-covered / plan-pass cases below).
beforeEach(fn () => bindHostedZones(['example.com', 'example.io']));

it('requests a fresh certificate when an already-issued one is missing a newly declared additional domain', function (): void {
    $captured = [];
    bindMockRoute53Client([
        ['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE-COM'],
        ['Name' => 'example.io.', 'Id' => '/hostedzone/ZONE-IO'],
    ], $captured);
    bindRoutedAcmClient([
        'ListCertificates' => new Result(['CertificateSummaryList' => [[
            'DomainName' => 'example.com',
            'CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/old-1234',
            'Status' => 'ISSUED',
            // The live cert only covers the primary — app.example.io was added to
            // yolo.yml after this certificate was already issued.
            'SubjectAlternativeNameSummaries' => ['example.com', '*.example.com'],
        ]]]),
        'RequestCertificate' => new Result(['CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/new-5678']),
        'DescribeCertificate' => new Result(['Certificate' => ['DomainValidationOptions' => [
            [
                'ValidationMethod' => 'DNS',
                'ValidationDomain' => 'example.com',
                'ResourceRecord' => ['Name' => '_x.example.com', 'Type' => 'CNAME', 'Value' => '_y.acm-validations.aws'],
            ],
        ]]]),
    ], $captured);

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['example.com', 'app.example.io']]],
    ]);

    expect((new SyncSslCertificateStep())(['dry-run' => false]))->toBe(StepResult::SYNCED);

    $requestArgs = collect($captured)->firstWhere('name', 'RequestCertificate')['args'];

    expect($requestArgs['SubjectAlternativeNames'])->toBe(['*.example.com', 'app.example.io']);
});

it('reports WOULD_SYNC on the plan pass without requesting a new certificate', function (): void {
    $captured = [];
    bindRoutedAcmClient([
        'ListCertificates' => new Result(['CertificateSummaryList' => [[
            'DomainName' => 'example.com',
            'CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/old-1234',
            'Status' => 'ISSUED',
            'SubjectAlternativeNameSummaries' => ['example.com', '*.example.com'],
        ]]]),
    ], $captured);

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['example.com', 'app.example.io']]],
    ]);

    expect((new SyncSslCertificateStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);

    expect(collect($captured)->where('name', 'RequestCertificate'))->toBeEmpty();
});

it('stays SYNCED with no re-request when the issued certificate already covers every additional domain', function (): void {
    $captured = [];
    bindRoutedAcmClient([
        'ListCertificates' => new Result(['CertificateSummaryList' => [[
            'DomainName' => 'example.com',
            'CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/current-1234',
            'Status' => 'ISSUED',
            'SubjectAlternativeNameSummaries' => ['example.com', '*.example.com', 'app.example.io'],
        ]]]),
    ], $captured);

    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['example.com', 'app.example.io']]],
    ]);

    expect((new SyncSslCertificateStep())(['dry-run' => false]))->toBe(StepResult::SYNCED);

    expect(collect($captured)->where('name', 'RequestCertificate'))->toBeEmpty();
});
