<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\ResolvesServerGroups;
use Codinglabs\Yolo\Steps\Sync\App\SyncTaskDefinitionStep;

class RegisterTaskDefinitionRevisionStep implements Step
{
    use ResolvesServerGroups;

    public function __construct(protected string $environment) {}

    public function __invoke(array $options): StepResult
    {
        foreach ($this->resolveServerGroups(Arr::get($options, 'group')) as $group) {
            Aws::ecs()->registerTaskDefinition(
                SyncTaskDefinitionStep::payload($group, Arr::get($options, 'app-version'))
            );
        }

        return StepResult::CREATED;
    }
}
