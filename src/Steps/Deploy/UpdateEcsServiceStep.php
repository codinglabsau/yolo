<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class UpdateEcsServiceStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(): StepResult
    {
        Aws::ecs()->updateService([
            'cluster' => AwsResources::ecsClusterName(),
            'service' => AwsResources::ecsServiceName(),
            'taskDefinition' => AwsResources::ecsTaskFamily(),
            'forceNewDeployment' => true,
        ]);

        return StepResult::SYNCED;
    }
}
