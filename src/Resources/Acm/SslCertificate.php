<?php

namespace Codinglabs\Yolo\Resources\Acm;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Acm;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A DNS-validated ACM certificate covering a domain and its wildcard
 * (`*.{domain}`), addressed by domain so the solo and tenant steps share it.
 *
 * Unlike the create-or-sync Resources, a certificate is a small state machine
 * (request → pending validation → issued), so it doesn't implement the Resource
 * contract — the step drives the states and this class owns the AWS calls,
 * including the DNS-validation record + the wait for issuance.
 */
class SslCertificate implements Deletable
{
    public function __construct(protected string $domain) {}

    /**
     * The certificate summary (DomainName, Status, CertificateArn), or null when
     * no certificate exists yet for the domain.
     *
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

    /**
     * Teardown removes the certificate. ACM refuses to delete a cert still
     * associated with a load-balancer listener (ResourceInUseException), so this
     * runs late in the teardown order — after the app's listener rule and the
     * listener's SNI certificate association have both been removed upstream. By
     * then the cert is detached and a plain deleteCertificate is correct; this
     * class never manages the listener association itself, so there's nothing to
     * unwind here. A concurrent not-found is tolerated.
     */
    public function delete(): void
    {
        $certificate = $this->find();

        if ($certificate === null) {
            return;
        }

        try {
            Aws::acm()->deleteCertificate([
                'CertificateArn' => $certificate['CertificateArn'],
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return;
            }

            throw $e;
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
     * Publish the DNS validation record into the domain's hosted zone, then block
     * until ACM reports the certificate ISSUED. The apex and wildcard share one
     * validation record, so the wildcard option is filtered out to avoid a
     * redundant UPSERT.
     */
    public function validate(string $certificateArn): void
    {
        do {
            $certificate = Aws::acm()->describeCertificate([
                'CertificateArn' => $certificateArn,
            ])['Certificate'];

            // The result is incomplete on the first request — give AWS a moment.
            sleep(2);
        } while (
            ! array_key_exists('DomainValidationOptions', $certificate) ||
            ! collect($certificate['DomainValidationOptions'])
                ->every(fn (array $option): bool => array_key_exists('ResourceRecord', $option))
        );

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
            'HostedZoneId' => (new HostedZone($this->domain))->arn(),
        ]);

        while (Acm::certificate($this->domain)['Status'] !== 'ISSUED') {
            sleep(2);
        }
    }
}
