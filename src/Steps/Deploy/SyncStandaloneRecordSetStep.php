<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SyncsRecordSets;
use Codinglabs\Yolo\Contracts\ExecutesStandaloneStep;

class SyncStandaloneRecordSetStep implements ExecutesStandaloneStep
{
    use SyncsRecordSets;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('apex') && ! Manifest::has('domain')) {
            return StepResult::SKIPPED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $this->syncRecordSet(
                apex: Manifest::apex(),
                domain: Manifest::get('domain'),
            );

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
