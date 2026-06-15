<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Resources\Iam\AdminPolicy;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Provisions the env-shared `yolo-{env}-admin` policy — the write surface that,
 * with {@see ObserverPolicy}, caps the Admin tier
 * (`yolo sync` / `yolo scale`) to YOLO-owned resources. Carried by
 * {@see AdminRole}, attached by
 * {@see AttachAdminRolePolicyStep}.
 */
class SyncAdminPolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AdminPolicy(), $options);
    }
}
