<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Fargate\EcsCluster;
use Codinglabs\Yolo\Resources\Fargate\EcsService;

class UpdateEcsServiceStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(): StepResult
    {
        $service = new EcsService();

        Aws::ecs()->updateService([
            'cluster' => (new EcsCluster())->name(),
            'service' => $service->name(),
            // The task definition family is the web service name (see EcsService).
            'taskDefinition' => $service->name(),
            'forceNewDeployment' => true,
        ]);

        return StepResult::SYNCED;
    }
}
