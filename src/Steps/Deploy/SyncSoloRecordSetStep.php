<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Deploy;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SyncsRecordSets;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;

/**
 * UPSERTs the records for the app's *own* domain. Gated on having a domain rather
 * than on being solo: a tenanted app that declares a `domain` of its own (serving
 * its tenants as subdomains of it) needs these records exactly as a solo app does
 * — tenancy is an orthogonal axis. A tenant's own records are written by
 * {@see SyncMultitenancyRecordSetStep}.
 */
class SyncSoloRecordSetStep implements ExecutesWebStep
{
    use SyncsRecordSets;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::hasDomain()) {
            return StepResult::SKIPPED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $this->syncRecordSet(
                apex: Manifest::apex(),
                domain: (string) Manifest::domain(),
                wildcardHost: Manifest::wildcardHost(),
            );

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
