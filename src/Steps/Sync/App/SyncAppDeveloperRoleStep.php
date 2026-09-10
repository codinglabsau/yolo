<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\AppDeveloperRole;

/** No GitHub-repo gate: every app gets a developer role so a data-write grant can name it. */
class SyncAppDeveloperRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AppDeveloperRole(), $options);
    }
}
