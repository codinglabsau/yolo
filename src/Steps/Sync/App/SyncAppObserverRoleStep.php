<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\AppObserverRole;

class SyncAppObserverRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        // No GitHub-repo gate (unlike the deployer role): every app gets a per-app
        // observer role so a read grant can name it. Trust-policy drift rides
        // through SynchronisesConfiguration on the role into the plan.
        return $this->syncResource(new AppObserverRole(), $options);
    }
}
