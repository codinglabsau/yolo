<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWafStep;
use Codinglabs\Yolo\Resources\WafV2\BlockIpSet;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncWafBlockIpSetStep implements ExecutesWafStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new BlockIpSet(), $options);
    }
}
