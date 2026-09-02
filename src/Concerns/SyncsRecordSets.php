<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;

trait SyncsRecordSets
{
    use ResolvesCanonicalHost;

    public function syncRecordSet(string $apex, string $domain, ?string $wildcardHost): void
    {
        Aws::route53()->changeResourceRecordSets([
            'ChangeBatch' => [
                'Changes' => $this->generateChanges($apex, $domain, $wildcardHost),
                'Comment' => 'Created by yolo CLI',
            ],
            'HostedZoneId' => Route53::hostedZone($apex)['Id'],
        ]);
    }

    protected function generateChanges(string $apex, string $domain, ?string $wildcardHost): array
    {
        $ALB = ElbV2::loadBalancer((new LoadBalancer())->name());

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
