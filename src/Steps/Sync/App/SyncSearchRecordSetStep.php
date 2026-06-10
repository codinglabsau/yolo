<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Resources\ElbV2\SearchListenerRule;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * UPSERTs the app's `search.{apex}` alias record onto the env ALB, into the
 * app's existing apex hosted zone. The app records themselves are synced at
 * deploy time (SyncSoloRecordSetStep), but the search host has no deploy of its
 * own — its lifecycle is sync, like the listener rule it pairs with.
 */
class SyncSearchRecordSetStep implements ExecutesWebStep
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::scoutDriver() !== 'meilisearch') {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $host = SearchListenerRule::host();

        try {
            $zone = Route53::hostedZone(Manifest::apex());
            $loadBalancer = ElbV2::loadBalancer((new LoadBalancer())->name());
        } catch (ResourceDoesNotExistException $e) {
            // The zone / ALB aren't provisioned yet (a greenfield plan pass) —
            // report the record pending; on apply they exist (created earlier in
            // scope order) so a genuine miss is a hard fail.
            if ($dryRun) {
                $this->recordChange(Change::make("record $host", null, 'ALB alias (pending)'));

                return StepResult::WOULD_CREATE;
            }

            throw $e;
        }

        $current = Route53::recordSet($zone['Id'], $host, 'A');
        $currentTarget = rtrim((string) ($current['AliasTarget']['DNSName'] ?? ''), '.');

        if ($current !== null && $currentTarget === rtrim((string) $loadBalancer['DNSName'], '.')) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(
            "record $host",
            $current === null ? 'absent' : $currentTarget,
            $loadBalancer['DNSName'],
        ));

        if ($dryRun) {
            return $current === null ? StepResult::WOULD_CREATE : StepResult::WOULD_SYNC;
        }

        Aws::route53()->changeResourceRecordSets([
            'ChangeBatch' => [
                'Changes' => [
                    [
                        'Action' => 'UPSERT',
                        'ResourceRecordSet' => [
                            'AliasTarget' => [
                                'DNSName' => $loadBalancer['DNSName'],
                                'HostedZoneId' => $loadBalancer['CanonicalHostedZoneId'],
                                'EvaluateTargetHealth' => false,
                            ],
                            'Name' => $host,
                            'Type' => 'A',
                        ],
                    ],
                ],
                'Comment' => 'Created by yolo CLI',
            ],
            'HostedZoneId' => $zone['Id'],
        ]);

        return $current === null ? StepResult::CREATED : StepResult::SYNCED;
    }
}
