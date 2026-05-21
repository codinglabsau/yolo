<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class WaitForServiceStableStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'watch')) {
            return StepResult::SKIPPED;
        }

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
