<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\InternetGateway;

class SyncInternetGatewayStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $internetGateway = new InternetGateway();

        return $this->syncResource($internetGateway, $options);
    }
}
