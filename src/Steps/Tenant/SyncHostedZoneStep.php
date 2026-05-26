<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncHostedZoneStep extends TenantStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new HostedZone($this->config['apex']), $options);
    }
}
