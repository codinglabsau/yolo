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
        // `build:` is optional — a missing key would fatal array_map() in expandStep().
        return Manifest::get('build', []);
    }

    public function __invoke(array $options = []): StepResult
    {
        // The parent only renders the group header; the sub-steps do the work.
        return StepResult::SUCCESS;
    }
}
