<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;

class SyncRecordSetStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::isMultitenanted()) {
            return StepResult::SKIPPED;
        }

        $ALB = AwsResources::loadBalancer();

        if (! Arr::get($options, 'dry-run')) {
            $changes = [
                // naked domain alias
                [
                    'Action' => 'UPSERT', // CREATE|DELETE|UPSERT
                    'ResourceRecordSet' => [
                        'AliasTarget' => [
                            'DNSName' => $ALB['DNSName'],
                            'EvaluateTargetHealth' => false,
                            'HostedZoneId' => $ALB['CanonicalHostedZoneId'],
                        ],
                        'Name' => $this->config['domain'],
                        'Type' => 'A',
                    ],
                ],
            ];

            if (! $this->config['subdomain']) {
                // add a www. domain alias for non-subdomains
                $changes[] = [
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => [
                        'AliasTarget' => [
                            'DNSName' => $ALB['DNSName'],
                            'EvaluateTargetHealth' => false,
                            'HostedZoneId' => $ALB['CanonicalHostedZoneId'],
                        ],
                        'Name' => "www.{$this->config['domain']}",
                        'Type' => 'A',
                    ],
                ];
            }

            Aws::route53()->changeResourceRecordSets([
                'ChangeBatch' => [
                    'Changes' => $changes,
                    'Comment' => 'Created by yolo CLI',
                ],
                'HostedZoneId' => AwsResources::hostedZone($this->config['apex'])['Id'],
            ]);

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
