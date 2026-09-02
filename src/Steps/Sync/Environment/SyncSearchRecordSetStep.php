<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncSearchRecordSetStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $state = Lifecycle::state(Service::TYPESENSE);

        $dryRun = (bool) Arr::get($options, 'dry-run');

        if ($state === ServiceState::Teardown) {
            return $this->teardown($dryRun);
        }

        Typesense::requireSearchHost();

        $alb = $this->loadBalancer();

        if ($alb === null) {
            // Greenfield plan — the ALB's own create is still pending.
            $this->recordChange(Change::make('search alias', null, 'created (load balancer pending)'));

            return $dryRun ? StepResult::WOULD_CREATE : StepResult::SKIPPED;
        }

        $live = $this->liveAlias();

        if (static::aliasMatches($live, (string) $alb['DNSName'])) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make('search alias', $live ?? 'absent', $alb['DNSName']));

        if ($dryRun) {
            return $live === null ? StepResult::WOULD_CREATE : StepResult::WOULD_SYNC;
        }

        Aws::route53()->changeResourceRecordSets([
            'HostedZoneId' => $this->zoneId(),
            'ChangeBatch' => [
                'Comment' => 'Created by yolo CLI',
                'Changes' => [[
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => $this->recordSet($alb),
                ]],
            ],
        ]);

        return $live === null ? StepResult::CREATED : StepResult::SYNCED;
    }

    protected function teardown(bool $dryRun): StepResult
    {
        if (EnvManifest::get('domain') === null) {
            return StepResult::SKIPPED;
        }

        $alb = $this->loadBalancer();
        $live = $this->liveAlias();

        if ($live === null || $alb === null) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make('search alias', $live, null));

        if ($dryRun) {
            return StepResult::WOULD_DELETE;
        }

        Aws::route53()->changeResourceRecordSets([
            'HostedZoneId' => $this->zoneId(),
            'ChangeBatch' => [
                'Comment' => 'Deleted by yolo CLI',
                'Changes' => [[
                    'Action' => 'DELETE',
                    'ResourceRecordSet' => $this->recordSet(['DNSName' => $live, 'CanonicalHostedZoneId' => $this->liveAliasZoneId()]),
                ]],
            ],
        ]);

        return StepResult::DELETED;
    }

    /**
     * Route 53 returns the alias target with a trailing dot, ELBv2 without — both
     * are stripped before the case-insensitive compare, else a converged record
     * reads as drift, re-UPSERTs every sync and fails the deploy `sync --check`
     * gate. Static so the normalisation is pinned in a test.
     */
    public static function aliasMatches(?string $live, string $albDnsName): bool
    {
        return $live !== null && strcasecmp(rtrim($live, '.'), rtrim($albDnsName, '.')) === 0;
    }

    /**
     * @param  array<string, mixed>  $alb
     * @return array<string, mixed>
     */
    protected function recordSet(array $alb): array
    {
        return [
            'Name' => (string) Typesense::searchHost(),
            'Type' => 'A',
            'AliasTarget' => [
                'DNSName' => $alb['DNSName'],
                'HostedZoneId' => $alb['CanonicalHostedZoneId'],
                'EvaluateTargetHealth' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function loadBalancer(): ?array
    {
        try {
            return ElbV2::loadBalancer((new LoadBalancer())->name());
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    protected function zoneId(): string
    {
        return Route53::hostedZone((string) EnvManifest::get('domain'))['Id'];
    }

    protected function liveAlias(): ?string
    {
        return $this->liveRecord()['AliasTarget']['DNSName'] ?? null;
    }

    protected function liveAliasZoneId(): ?string
    {
        return $this->liveRecord()['AliasTarget']['HostedZoneId'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function liveRecord(): ?array
    {
        try {
            $zoneId = $this->zoneId();
        } catch (ResourceDoesNotExistException) {
            return null;
        }

        $host = rtrim((string) Typesense::searchHost(), '.') . '.';

        foreach (Aws::route53()->listResourceRecordSets([
            'HostedZoneId' => $zoneId,
            'StartRecordName' => $host,
            'StartRecordType' => 'A',
            'MaxItems' => '1',
        ])['ResourceRecordSets'] ?? [] as $record) {
            if (($record['Name'] ?? null) === $host && ($record['Type'] ?? null) === 'A') {
                return $record;
            }
        }

        return null;
    }
}
