<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Codinglabs\Yolo\Contracts\ExecutesWafStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncWafWebAclStep implements ExecutesWafStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new WebAcl(), $options);
    }
}
