<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;

/**
 * Reconciling, not additive, so removing a policy from the manifest detaches it
 * on the next sync — the role is YOLO's alone, so its attachment set is declarative.
 */
class AttachEcsTaskRolePoliciesStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        $desired = [
            $this->customerManagedPolicyArn((new EcsTaskPolicy())->name()),
            ...Manifest::taskRolePolicies(),
        ];

        return $this->reconcileRolePolicies(
            (new EcsTaskRole())->name(),
            $desired,
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
