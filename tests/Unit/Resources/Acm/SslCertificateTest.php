<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Acm\SslCertificate;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

describe('additional SANs', function (): void {
    it('requests the domain, its wildcard, and every additional SAN — no wildcard for the additional ones', function (): void {
        $captured = [];
        bindRoutedAcmClient(['RequestCertificate' => new Result(['CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/abcd-1234'])], $captured);

        (new SslCertificate('app.example.com', 'example.com', ['app.example.io', 'brand.other.com']))->request();

        $args = collect($captured)->firstWhere('name', 'RequestCertificate')['args'];

        expect($args['DomainName'])->toBe('app.example.com')
            ->and($args['SubjectAlternativeNames'])->toBe(['*.app.example.com', 'app.example.io', 'brand.other.com']);
    });

    it('writes each additional SAN\'s DNS validation record into its OWN apex zone', function (): void {
        $captured = [];
        bindMockRoute53Client([
            ['Name' => 'example.com.', 'Id' => '/hostedzone/ZONE-COM'],
            ['Name' => 'example.io.', 'Id' => '/hostedzone/ZONE-IO'],
        ], $captured);

        bindRoutedAcmClient([
            'DescribeCertificate' => new Result(['Certificate' => [
                'DomainValidationOptions' => [
                    [
                        'ValidationMethod' => 'DNS',
                        'ValidationDomain' => 'app.example.com',
                        'ResourceRecord' => ['Name' => '_x.app.example.com', 'Type' => 'CNAME', 'Value' => '_y.acm-validations.aws'],
                    ],
                    // The primary's wildcard shares the primary's validation record
                    // — filtered out to avoid a redundant UPSERT.
                    [
                        'ValidationMethod' => 'DNS',
                        'ValidationDomain' => '*.app.example.com',
                        'ResourceRecord' => ['Name' => '_x.app.example.com', 'Type' => 'CNAME', 'Value' => '_y.acm-validations.aws'],
                    ],
                    [
                        'ValidationMethod' => 'DNS',
                        'ValidationDomain' => 'app.example.io',
                        'ResourceRecord' => ['Name' => '_a.app.example.io', 'Type' => 'CNAME', 'Value' => '_b.acm-validations.aws'],
                    ],
                ],
            ]]),
            'ListCertificates' => new Result(['CertificateSummaryList' => [[
                'DomainName' => 'app.example.com',
                'CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/abcd-1234',
                'Status' => 'ISSUED',
            ]]]),
        ], $captured);

        (new SslCertificate('app.example.com', 'example.com', ['app.example.io']))->validate('arn:aws:acm:ap-southeast-2:111111111111:certificate/abcd-1234');

        $changeCalls = collect($captured)->where('name', 'ChangeResourceRecordSets')->values();

        expect($changeCalls)->toHaveCount(2);

        $byZone = $changeCalls->keyBy(fn (array $call): string => $call['args']['HostedZoneId']);

        expect($byZone->has('ZONE-COM'))->toBeTrue()
            ->and($byZone->has('ZONE-IO'))->toBeTrue();

        $comChanges = $byZone['ZONE-COM']['args']['ChangeBatch']['Changes'];
        $ioChanges = $byZone['ZONE-IO']['args']['ChangeBatch']['Changes'];

        expect($comChanges)->toHaveCount(1)
            ->and($comChanges[0]['ResourceRecordSet']['Name'])->toBe('_x.app.example.com')
            ->and($ioChanges)->toHaveCount(1)
            ->and($ioChanges[0]['ResourceRecordSet']['Name'])->toBe('_a.app.example.io');
    });

    it('reports no missing SANs when the live certificate already covers every desired one', function (): void {
        $certificate = new SslCertificate('app.example.com', 'example.com', ['app.example.io']);

        expect($certificate->isMissingAdditionalSans([
            'SubjectAlternativeNameSummaries' => ['app.example.com', '*.app.example.com', 'app.example.io'],
        ]))->toBeFalse();
    });

    it('reports a missing SAN when the live certificate does not yet cover a desired additional host', function (): void {
        $certificate = new SslCertificate('app.example.com', 'example.com', ['app.example.io', 'brand.other.com']);

        expect($certificate->isMissingAdditionalSans([
            'SubjectAlternativeNameSummaries' => ['app.example.com', '*.app.example.com', 'app.example.io'],
        ]))->toBeTrue();
    });

    it('reports no missing SANs when there are no additional SANs to begin with', function (): void {
        $certificate = new SslCertificate('app.example.com', 'example.com');

        expect($certificate->isMissingAdditionalSans(['SubjectAlternativeNameSummaries' => ['app.example.com', '*.app.example.com']]))->toBeFalse();
    });
});
