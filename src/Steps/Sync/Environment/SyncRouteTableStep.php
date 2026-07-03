<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

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

        return $this->syncResource($routeTable, $options);
    }
}
