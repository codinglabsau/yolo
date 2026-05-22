<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class WaitForServiceStableStep implements Step
{
    public function __construct(protected string $environment) {}

    /**
     * Always wait — a deploy that returns before the new task is healthy can
     * silently mask a crash-looping image or boot-time failure. Blocking here is
     * what makes `yolo deploy` mean "rolled out and healthy", and it gates the
     * DNS record-set step on a confirmed-healthy task.
     */
    public function __invoke(array $options): StepResult
    {
        Aws::ecs()->waitUntil('ServicesStable', [
            'cluster' => AwsResources::ecsClusterName(),
            'services' => [AwsResources::ecsServiceName()],
            '@waiter' => [
                'maxAttempts' => 60,
                'delay' => 15,
            ],
        ]);

        return StepResult::SUCCESS;
    }
}
