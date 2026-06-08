<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App\Solo;

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
