<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncPublicSubnetCStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $subnet = new PublicSubnet(2);

        if (Manifest::has('public-subnets') && $subnet->exists()) {
            return StepResult::CUSTOM_MANAGED;
        }

        return $this->syncResource($subnet, $options);
    }
}
