<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\AppDevelopersGroup;

/** Always provisioned (no repo gate), so a data-write grant can name a single app. */
class SyncAppDevelopersGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AppDevelopersGroup(), $options);
    }
}
