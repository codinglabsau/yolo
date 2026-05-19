<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Fargate\SyncTaskDefinitionStep;

class RegisterTaskDefinitionRevisionStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(array $options): StepResult
    {
        Aws::ecs()->registerTaskDefinition(
            SyncTaskDefinitionStep::payload(Arr::get($options, 'app-version'))
        );

        return StepResult::CREATED;
    }
}
