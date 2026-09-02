<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncVpcStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $vpc = new Vpc();

        if (! $vpc->exists()) {
            // Surface the auto-selected /16 so the operator sees which range the environment lands in.
            $this->recordChange(Change::make('cidr block', 'absent', $vpc->availableCidrBlock()));
        }

        return $this->syncResource($vpc, $options);
    }
}
