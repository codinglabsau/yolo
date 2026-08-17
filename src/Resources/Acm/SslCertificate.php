<?php

namespace Codinglabs\Yolo\Resources\Acm;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Acm;
use Codinglabs\Yolo\Manifest;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A DNS-validated ACM certificate covering a domain and its wildcard
 * (`*.{domain}`), plus any additional bare SANs, addressed by domain so the solo
 * and tenant steps share it.
 *
 * Unlike the create-or-sync Resources, a certificate is a small state machine
 * (request → pending validation → issued), so it doesn't implement the Resource
 * contract — the step drives the states and this class owns the AWS calls,
 * including the DNS-validation record + the wait for issuance.
 *
 * It is deliberately NOT Deletable: a cert is domain-level (a sibling environment
 * serving the same domain may hold one too, and ACM keys only by domain name), so
 * YOLO never deletes one — teardown withdraws the app's listener association (the
 * destroy:app cert-detach step) and leaves the certificate standing, the same
 * treatment the hosted zone gets.
 */
class SslCertificate
{
    /**
     * @param  string  $domain  the name the certificate is issued for (it also covers `*.{domain}`)
     * @param  string  $zone  the hosted zone the DNS validation record is written into — the
     *                        domain's apex, which is NOT the domain itself when the certificate
     *                        is issued for a subdomain (a wildcard-subdomain app)
     * @param  array<int, string>  $additionalSans  extra bare hosts to cover on this same
     *                                              certificate (a landlord serving several
     *                                              domains off one cert) — no wildcard is
     *                                              requested for these, only the literal host.
     *                                              Each one's own DNS validation record is
     *                                              written into ITS OWN apex zone
     *                                              ({@see Manifest::deriveApex()}), which may
     *                                              differ from `$zone`.
     */
    public function __construct(protected string $domain, protected string $zone, protected array $additionalSans = []) {}

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
     * Whether the live certificate's SAN set doesn't yet cover every desired
     * additional SAN — the trigger for requesting a fresh certificate, since ACM
     * certificates can't be amended in place once issued.
     *
     * @param  array<string, mixed>  $summary  as returned by {@see find()} (ListCertificates'
     *                                         CertificateSummaryList entry, which carries
     *                                         `SubjectAlternativeNameSummaries`)
     */
    public function isMissingAdditionalSans(array $summary): bool
    {
        return array_diff($this->additionalSans, $summary['SubjectAlternativeNameSummaries'] ?? []) !== [];
    }

    public function request(): string
    {
        return Aws::acm()->requestCertificate([
            'DomainName' => $this->domain,
            'SubjectAlternativeNames' => ["*.{$this->domain}", ...$this->additionalSans],
            'ValidationMethod' => 'DNS',
        ])['CertificateArn'];
    }

    /**
     * Publish the DNS validation record for every SAN into its own zone, then
     * block until ACM reports the certificate ISSUED. The primary domain and its
     * wildcard share one validation record, so the wildcard option is filtered
     * out to avoid a redundant UPSERT; each additional SAN gets its own option
     * and, unlike the primary, may resolve through a completely different apex
     * zone — grouped by zone so each writes exactly once.
     *
     * The primary domain's record goes into `$zone` rather than a zone named
     * after the domain itself: a certificate issued for a subdomain resolves
     * through its parent zone, and no zone of its own need exist.
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

        $zonesBySan = [$this->domain => $this->zone] + collect($this->additionalSans)
            ->mapWithKeys(fn (string $san): array => [$san => Manifest::deriveApex($san)])
            ->all();

        collect($certificate['DomainValidationOptions'])
            ->filter(fn (array $option): bool => $option['ValidationMethod'] === 'DNS'
                && ! str_starts_with((string) $option['ValidationDomain'], '*'))
            ->groupBy(fn (array $option): string => $zonesBySan[$option['ValidationDomain']] ?? $this->zone)
            ->each(function (Collection $options, string $zone): void {
                Aws::route53()->changeResourceRecordSets([
                    'ChangeBatch' => [
                        'Changes' => $options
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
                    'HostedZoneId' => (new HostedZone($zone))->arn(),
                ]);
            });

        while (Acm::certificate($this->domain)['Status'] !== 'ISSUED') {
            sleep(2);
        }
    }
}
