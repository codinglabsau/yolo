<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Rds\RdsSubnet;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncRdsSubnetStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $rdsSubnet = new RdsSubnet();

        if (Manifest::has('aws.rds.subnet') && $rdsSubnet->exists()) {
            return StepResult::CUSTOM_MANAGED;
        }

        return $this->syncResource($rdsSubnet, $options);
    }
}
