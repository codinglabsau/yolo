<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;
use Codinglabs\Yolo\Resources\Iam\AppObserverRole;
use Codinglabs\Yolo\Resources\Iam\AppObserverPolicy;

/**
 * Attaches the per-app {@see AppObserverPolicy} to the {@see AppObserverRole}, so
 * a profile assuming the app observer role gets the full read surface with log
 * content fenced to this app's group — and nothing mutating. Runs after both are
 * provisioned; the attach reconciles idempotently.
 */
class AttachAppObserverRolePolicyStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        return $this->attachRolePolicies(
            (new AppObserverRole())->name(),
            [$this->customerManagedPolicyArn((new AppObserverPolicy())->name())],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
