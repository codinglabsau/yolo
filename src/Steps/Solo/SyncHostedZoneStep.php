<?php

namespace Codinglabs\Yolo\Steps\Solo;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncHostedZoneStep implements ExecutesWebStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new HostedZone(Manifest::apex()), $options);
    }
}
