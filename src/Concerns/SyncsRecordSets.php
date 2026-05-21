<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\AwsResources;

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
            'HostedZoneId' => AwsResources::hostedZone($apex)['Id'],
        ]);
    }

    protected function generateChanges(string $apex, string $domain): array
    {
        $ALB = AwsResources::loadBalancer();

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
