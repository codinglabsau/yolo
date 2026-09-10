<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\DevelopersGroup;

/** YOLO owns the group + its assume-role policy; membership is the human lever. */
class SyncDevelopersGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new DevelopersGroup(), $options);
    }
}
