<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;

trait SyncsRecordSets
{
    use ResolvesCanonicalHost;

    public function syncRecordSet(string $apex, string $domain, ?string $wildcardHost = null): void
    {
        Aws::route53()->changeResourceRecordSets([
            'ChangeBatch' => [
                'Changes' => $this->generateChanges($apex, $domain, $wildcardHost),
                'Comment' => 'Created by yolo CLI',
            ],
            'HostedZoneId' => Route53::hostedZone($apex)['Id'],
        ]);
    }

    protected function generateChanges(string $apex, string $domain, ?string $wildcardHost = null): array
    {
        $ALB = ElbV2::loadBalancer((new LoadBalancer())->name());

        // The canonical host plus, when it's one half of the apex/www pair, its
        // sibling — both resolve to the ALB so the redirect rule can 301 the
        // sibling to the canonical host. A bare subdomain has no sibling. A
        // wildcard-subdomain app adds `*.{domain}`, so every subdomain resolves
        // without a record per tenant.
        $hosts = $this->aliasedHosts($apex, $domain, $wildcardHost);

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
