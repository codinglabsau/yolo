<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\HasSubSteps;
use Codinglabs\Yolo\Contracts\RunsOnBuild;

class ExecuteBuildStepsStep implements HasSubSteps, RunsOnBuild
{
    public function subSteps(): array
    {
        // `build:` is optional — coalesce to [] so an app with no build hooks
        // expands to zero sub-steps rather than fataling array_map() in expandStep().
        return Manifest::get('build', []);
    }

    public function __invoke(array $options = []): StepResult
    {
        // The work is the sub-steps this step expands into (subSteps()); running
        // the parent itself is a no-op that just renders the group header.
        return StepResult::SUCCESS;
    }
}
