<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\ObserverRole;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;

/**
 * Attaches the read-only {@see ObserverPolicy} policy to the {@see ObserverRole},
 * so a profile assuming the role gets exactly YOLO's inspection surface — and
 * nothing mutating. Runs after the role and the policy are provisioned; the
 * attach reconciles idempotently (a missing attachment is recorded as a plan-time
 * change and applied, an existing one is left alone).
 */
class AttachObserverRolePolicyStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        return $this->attachRolePolicies(
            (new ObserverRole())->name(),
            [$this->customerManagedPolicyArn((new ObserverPolicy())->name())],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
