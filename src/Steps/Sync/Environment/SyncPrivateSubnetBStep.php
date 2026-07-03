<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\PrivateSubnet;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncPrivateSubnetBStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $subnet = new PrivateSubnet(1);

        if (Manifest::has('private-subnets') && $subnet->exists()) {
            return StepResult::CUSTOM_MANAGED;
        }

        if (! $subnet->exists()) {
            // Surface the /24 in the plan before it's created — deterministic on
            // a YOLO VPC, discovered on an adopted one.
            $this->recordChange(Change::make('cidr block', 'absent', $subnet->availableCidrBlock()));
        }

        return $this->syncResource($subnet, $options);
    }
}
