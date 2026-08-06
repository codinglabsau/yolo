<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App\Tenant;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Steps\Deploy\SyncMultitenancyRecordSetStep;
use Codinglabs\Yolo\Steps\Destroy\App\WithdrawAppDnsRecordsStep;

/**
 * Withdraws one tenant's DNS records from that tenant's own hosted zone — the
 * per-tenant twin of {@see WithdrawAppDnsRecordsStep},
 * and the teardown of what {@see SyncMultitenancyRecordSetStep}
 * writes at deploy.
 *
 * The zone is addressed through {@see HostedZone::forTenant()} so the withdrawal
 * is keyed to that tenant's own hosts (its domain, its apex/`www` sibling, its
 * wildcard) — never the app's and never a sibling tenant's. As everywhere else,
 * the zone itself is never deleted: it is the tenant's domain-level
 * infrastructure, which is exactly what makes absorbing a pre-existing custom
 * domain safe to undo.
 */
class WithdrawDnsRecordsStep extends TenantStep
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // A tenant served under the app's own wildcard has no zone and no records
        // of its own — the app's `*.{domain}` alias already resolved it, and that
        // record is withdrawn by the app-level step.
        if (! isset($this->config['domain']) || Manifest::servesDomain($this->config['domain'])) {
            return StepResult::SKIPPED;
        }

        $zone = HostedZone::forTenant($this->config);

        if (! $zone->exists()) {
            return StepResult::SKIPPED;
        }

        $records = $zone->appRecords();

        if ($records === []) {
            return StepResult::SKIPPED;
        }

        foreach ($records as $record) {
            $this->recordChange(Change::make(sprintf('%s %s', $record['Type'], $record['Name']), 'present', null));
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $zone->removeAppRecords();

        return StepResult::DELETED;
    }
}
