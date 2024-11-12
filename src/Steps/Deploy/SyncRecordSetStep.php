<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SyncsRecordSets;

class SyncRecordSetStep implements Step
{
    use SyncsRecordSets;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::isMultitenanted()) {
            return StepResult::SKIPPED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $this->syncRecordSet(
                apex: Manifest::get('apex', Manifest::get('domain')),
                domain: Manifest::get('domain'),
                subdomain: Manifest::get('domain', default: false)
            );

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
