<?php

namespace Codinglabs\Yolo\Resources\Acm;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Acm;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * DNS-validated ACM certificate for a domain + its wildcard, addressed by domain so
 * the solo and tenant steps share it. Not a Resource: a certificate is a state
 * machine (request → pending validation → issued) the step drives.
 *
 * Deliberately NOT Deletable: ACM keys only by domain name, so a sibling environment
 * on the same domain may hold one too. Teardown withdraws the listener association
 * and leaves the certificate standing, like the hosted zone.
 */
class SslCertificate
{
    /**
     * @param  string  $zone  the apex zone the validation record is written into — NOT the
     *                        domain itself when the certificate is for a subdomain
     */
    public function __construct(protected string $domain, protected string $zone) {}

    /**
     * @return array<string, mixed>|null
     */
    public function find(): ?array
    {
        try {
            return Acm::certificate($this->domain);
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    public function request(): string
    {
        return Aws::acm()->requestCertificate([
            'DomainName' => $this->domain,
            'SubjectAlternativeNames' => ["*.{$this->domain}"],
            'ValidationMethod' => 'DNS',
        ])['CertificateArn'];
    }

    /**
     * The domain and its wildcard share one validation record, so the wildcard option
     * is filtered out. The record goes into the *apex* zone — a subdomain certificate
     * resolves through its parent zone and needs no zone of its own.
     */
    public function validate(string $certificateArn): void
    {
        while (true) {
            $certificate = Aws::acm()->describeCertificate([
                'CertificateArn' => $certificateArn,
            ])['Certificate'];

            if (
                array_key_exists('DomainValidationOptions', $certificate) &&
                collect($certificate['DomainValidationOptions'])
                    ->every(fn (array $option): bool => array_key_exists('ResourceRecord', $option))
            ) {
                break;
            }

            // The result is incomplete on the first request — give AWS a moment.
            sleep(2);
        }

        Aws::route53()->changeResourceRecordSets([
            'ChangeBatch' => [
                'Changes' => collect($certificate['DomainValidationOptions'])
                    ->filter(fn (array $option): bool => $option['ValidationMethod'] === 'DNS'
                        && ! str_starts_with((string) $option['ValidationDomain'], '*'))
                    ->map(fn (array $option): array => [
                        'Action' => 'UPSERT',
                        'ResourceRecordSet' => [
                            'Name' => $option['ResourceRecord']['Name'],
                            'Type' => $option['ResourceRecord']['Type'],
                            'ResourceRecords' => [['Value' => $option['ResourceRecord']['Value']]],
                            'TTL' => 300,
                        ],
                    ])
                    ->values()
                    ->all(),
                'Comment' => 'Created by yolo CLI',
            ],
            'HostedZoneId' => (new HostedZone($this->zone))->arn(),
        ]);

        while (Acm::certificate($this->domain)['Status'] !== 'ISSUED') {
            sleep(2);
        }
    }
}
