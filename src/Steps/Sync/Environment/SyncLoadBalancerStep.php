<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncLoadBalancerStep implements ExecutesWebStep, LongRunning
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new LoadBalancer(), $options);
    }

    public function patienceMessage(): string
    {
        return 'Waiting for the load balancer to become active — AWS usually takes a minute or two';
    }
}
