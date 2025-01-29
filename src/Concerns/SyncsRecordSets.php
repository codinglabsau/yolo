<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\AwsResources;

trait SyncsRecordSets
{
    public function syncRecordSet(string $apex, string $domain, bool $subdomain = false): void
    {
        $ALB = AwsResources::loadBalancer();

        $changes = [
            // naked domain alias
            [
                'Action' => 'UPSERT',
                'ResourceRecordSet' => [
                    'AliasTarget' => [
                        'DNSName' => $ALB['DNSName'],
                        'EvaluateTargetHealth' => false,
                        'HostedZoneId' => $ALB['CanonicalHostedZoneId'],
                    ],
                    'Name' => $domain,
                    'Type' => 'A',
                ],
            ],
        ];

        if (! $subdomain) {
            // add a www. domain alias for non-subdomains
            $changes[] = [
                'Action' => 'UPSERT',
                'ResourceRecordSet' => [
                    'AliasTarget' => [
                        'DNSName' => $ALB['DNSName'],
                        'EvaluateTargetHealth' => false,
                        'HostedZoneId' => $ALB['CanonicalHostedZoneId'],
                    ],
                    'Name' => "www.$domain",
                    'Type' => 'A',
                ],
            ];
        }

        Aws::route53()->changeResourceRecordSets([
            'ChangeBatch' => [
                'Changes' => $changes,
                'Comment' => 'Created by yolo CLI',
            ],
            'HostedZoneId' => AwsResources::hostedZone($apex)['Id'],
        ]);
    }
}
