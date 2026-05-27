<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;

trait SyncsRecordSets
{
    use DetectsSubdomains;

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

        // handle apex and www. subdomains
        if ($this->domainHasWwwSubdomain($apex, $domain)) {
            // apex record, such as codinglabs.com.au
            return [
                [
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => [
                        'AliasTarget' => [
                            'DNSName' => $ALB['DNSName'],
                            'HostedZoneId' => $ALB['CanonicalHostedZoneId'],
                            'EvaluateTargetHealth' => false,
                        ],
                        'Name' => $apex,
                        'Type' => 'A',
                    ],
                ],
                [
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => [
                        'AliasTarget' => [
                            'DNSName' => $ALB['DNSName'],
                            'HostedZoneId' => $ALB['CanonicalHostedZoneId'],
                            'EvaluateTargetHealth' => false,
                        ],
                        'Name' => str_starts_with($domain, 'www.')
                            ? $domain
                            : "www.$domain",
                        'Type' => 'A',
                    ],
                ],
            ];
        }

        // subdomain record, like foo.codinglabs.com.au
        return [
            [
                'Action' => 'UPSERT',
                'ResourceRecordSet' => [
                    'AliasTarget' => [
                        'DNSName' => $ALB['DNSName'],
                        'HostedZoneId' => $ALB['CanonicalHostedZoneId'],
                        'EvaluateTargetHealth' => false,
                    ],
                    'Name' => $domain,
                    'Type' => 'A',
                ],
            ],
        ];
    }
}
