<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\SyncsRecordSets;

class SyncMultitenancyRecordSetStep extends TenantStep
{
    use SyncsRecordSets;

    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            $this->syncRecordSet(
                apex: $this->config['apex'],
                domain: $this->config['domain'],
                subdomain: $this->config['subdomain']
            );

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
