<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Network\Vpc;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncVpcStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $vpc = new Vpc();

        if (Manifest::has('aws.vpc') && $vpc->exists()) {
            return StepResult::CUSTOM_MANAGED;
        }

        return $this->syncResource($vpc, $options);
    }
}
