<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;
use Codinglabs\Yolo\Resources\Iam\AppObserverRole;
use Codinglabs\Yolo\Resources\Iam\AppObserverPolicy;

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
