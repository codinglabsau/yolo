<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class UpdateEcsServiceStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(): StepResult
    {
        Aws::ecs()->updateService([
            'cluster' => AwsLookups::ecsClusterName(),
            'service' => AwsLookups::ecsServiceName(),
            'taskDefinition' => AwsLookups::ecsTaskFamily(),
            'forceNewDeployment' => true,
        ]);

        return StepResult::SYNCED;
    }
}
