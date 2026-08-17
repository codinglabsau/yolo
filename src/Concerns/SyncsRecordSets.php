<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;

trait SyncsRecordSets
{
    use ResolvesCanonicalHost;

    public function syncRecordSet(string $apex, string $domain, ?string $wildcardHost): void
    {
        $this->writeAliasRecords($apex, $this->aliasedHosts($apex, $domain, $wildcardHost));
    }

    /**
     * UPSERTs a single A-alias record for one additional landlord host — no
     * apex/`www` sibling and no wildcard inferred, since an additional host is
     * served exactly as declared. Its own apex may differ from the app's primary
     * domain (a landlord serving several brands), so the caller resolves it via
     * {@see Manifest::deriveApex()} rather than this trait
     * assuming it shares the primary's zone.
     */
    public function syncAdditionalHostRecordSet(string $apex, string $host): void
    {
        $this->writeAliasRecords($apex, [$host]);
    }

    /**
     * @param  array<int, string>  $hosts
     */
    protected function writeAliasRecords(string $apex, array $hosts): void
    {
        Aws::route53()->changeResourceRecordSets([
            'ChangeBatch' => [
                'Changes' => $this->generateChanges($hosts),
                'Comment' => 'Created by yolo CLI',
            ],
            'HostedZoneId' => Route53::hostedZone($apex)['Id'],
        ]);
    }

    /**
     * @param  array<int, string>  $hosts
     */
    protected function generateChanges(array $hosts): array
    {
        $ALB = ElbV2::loadBalancer((new LoadBalancer())->name());

        return array_map(fn (string $host): array => [
            'Action' => 'UPSERT',
            'ResourceRecordSet' => [
                'AliasTarget' => [
                    'DNSName' => $ALB['DNSName'],
                    'HostedZoneId' => $ALB['CanonicalHostedZoneId'],
                    'EvaluateTargetHealth' => false,
                ],
                'Name' => $host,
                'Type' => 'A',
            ],
        ], $hosts);
    }
}
