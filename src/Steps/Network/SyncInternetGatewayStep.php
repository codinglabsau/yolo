<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Network\InternetGateway;

class SyncInternetGatewayStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $internetGateway = new InternetGateway();

        if (Manifest::has('aws.internet-gateway') && $internetGateway->exists()) {
            return StepResult::CUSTOM_MANAGED;
        }

        return $this->syncResource($internetGateway, $options);
    }
}
