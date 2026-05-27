<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;

class SyncPublicSubnetBStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $subnet = new PublicSubnet(1);

        if (Manifest::has('aws.public-subnets') && $subnet->exists()) {
            return StepResult::CUSTOM_MANAGED;
        }

        return $this->syncResource($subnet, $options);
    }
}
