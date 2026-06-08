<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;

trait SyncsRecordSets
{
    use ResolvesCanonicalHost;

    public function syncRecordSet(string $apex, string $domain): void
    {
        Aws::route53()->changeResourceRecordSets([
            'ChangeBatch' => [
                'Changes' => $this->generateChanges($apex, $domain),
                'Comment' => 'Created by yolo CLI',
            ],
            'HostedZoneId' => Route53::hostedZone($apex)['Id'],
        ]);
    }

    protected function generateChanges(string $apex, string $domain): array
    {
        $ALB = ElbV2::loadBalancer((new LoadBalancer())->name());

        // The canonical host plus, when it's one half of the apex/www pair, its
        // sibling — both resolve to the ALB so the redirect rule can 301 the
        // sibling to the canonical host. A bare subdomain has no sibling.
        $hosts = $this->hasWwwSibling($apex, $domain)
            ? [$domain, $this->wwwSibling($apex, $domain)]
            : [$domain];

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
