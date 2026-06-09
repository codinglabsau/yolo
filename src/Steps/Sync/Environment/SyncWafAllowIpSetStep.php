<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWafStep;
use Codinglabs\Yolo\Resources\WafV2\AllowIpSet;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncWafAllowIpSetStep implements ExecutesWafStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AllowIpSet(), $options);
    }
}
