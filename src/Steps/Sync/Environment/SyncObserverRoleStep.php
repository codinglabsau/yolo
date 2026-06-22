<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\ObserverRole;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Provisions the env-shared read-only `yolo-{env}-observer-role` an
 * operator or agent assumes for safe inspection. Unconditional, like the observer
 * policy it carries — it stands up with the environment. The read-only policy is
 * attached by {@see AttachObserverRolePolicyStep}, which runs after this.
 */
class SyncObserverRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new ObserverRole(), $options);
    }
}
