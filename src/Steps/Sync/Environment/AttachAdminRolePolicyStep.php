<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Resources\Iam\AdminPolicy;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;

/**
 * Attaches both halves of the Admin tier to the {@see AdminRole}: the read-only
 * {@see ObserverPolicy} (reuse — admin reads exactly what the observer reads) and
 * the {@see AdminPolicy} write surface. An operator assuming the role can read the
 * whole stack and write everything YOLO provisions — and nothing beyond. Runs
 * after the role and both policies exist; the attach reconciles idempotently.
 */
class AttachAdminRolePolicyStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        return $this->attachRolePolicies(
            (new AdminRole())->name(),
            [
                $this->customerManagedPolicyArn((new ObserverPolicy())->name()),
                $this->customerManagedPolicyArn((new AdminPolicy())->name()),
            ],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
