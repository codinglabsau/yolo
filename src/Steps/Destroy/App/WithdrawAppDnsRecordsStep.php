<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Route53\HostedZone;

/**
 * The hosted zone is never deleted — the registrar's NS delegation, the domain's
 * email/verification DNS and sibling environments' records all live in it. Only
 * the A/AAAA records YOLO inserted for this app go.
 */
class WithdrawAppDnsRecordsStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // Per-tenant records are withdrawn by the per-tenant teardown.
        if (! Manifest::hasDomain()) {
            return StepResult::SKIPPED;
        }

        $zone = new HostedZone(Manifest::apex());

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
