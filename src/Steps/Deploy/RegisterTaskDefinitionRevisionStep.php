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

    /**
     * Mint a fresh, immutable task-definition revision (stamped with this deploy's
     * image tag) for each targeted service group — every group the app runs by
     * default, or the subset named by --group — so UpdateEcsServiceStep can roll
     * each service onto it.
     */
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
