<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeveloperRole;
use Codinglabs\Yolo\Resources\Iam\DataReadPolicy;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;
use Codinglabs\Yolo\Resources\Iam\BackupsReadPolicy;

/**
 * Observer reads + data reads + dump reads; never a write policy. Exact, so a policy
 * dropped from this list (the data writes once were here) is detached on the next sync.
 */
class AttachDeveloperRolePoliciesStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        return $this->reconcileRolePolicies(
            (new DeveloperRole())->name(),
            [
                $this->customerManagedPolicyArn((new ObserverPolicy())->name()),
                $this->customerManagedPolicyArn((new DataReadPolicy())->name()),
                $this->customerManagedPolicyArn((new BackupsReadPolicy())->name()),
            ],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
