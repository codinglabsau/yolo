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
 * Reconciles the managed-policy attachments on this app's task role against the
 * desired set: the YOLO baseline task policy plus any ARNs the manifest declares
 * under `task-role-policies`. Reconciling (not merely additive) so removing a
 * policy from the manifest detaches it on the next sync — the role is YOLO's
 * alone, so its attachment set is declarative, with no feature-toggle-off orphan.
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
