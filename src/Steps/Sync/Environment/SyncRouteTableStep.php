<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\RouteTable;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncRouteTableStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $routeTable = new RouteTable();

        if (Manifest::has('route-table') && $routeTable->exists()) {
            return StepResult::CUSTOM_MANAGED;
        }

        return $this->syncResource($routeTable, $options);
    }
}
