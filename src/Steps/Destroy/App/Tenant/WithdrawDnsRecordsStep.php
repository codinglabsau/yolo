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
 * Per-tenant twin of {@see WithdrawAppDnsRecordsStep}; the teardown of what
 * {@see SyncMultitenancyRecordSetStep} writes at deploy. The tenant's zone is
 * never deleted — that's what makes absorbing a pre-existing custom domain safe
 * to undo.
 */
class WithdrawDnsRecordsStep extends TenantStep
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // A tenant under the app's own wildcard has no zone of its own; the app's
        // `*.{domain}` alias is withdrawn by the app-level step.
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
