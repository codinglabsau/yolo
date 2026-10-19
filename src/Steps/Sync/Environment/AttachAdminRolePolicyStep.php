<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Resources\Iam\AdminPolicy;
use Codinglabs\Yolo\Resources\Iam\DataReadPolicy;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;
use Codinglabs\Yolo\Resources\Iam\DataWritePolicy;
use Codinglabs\Yolo\Resources\Iam\DumpsReadPolicy;

/**
 * The observer, data and dump documents are reused rather than duplicated: admin
 * reads exactly what the observer and developer read, plus the data writes and the
 * infrastructure.
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
                $this->customerManagedPolicyArn((new DataReadPolicy())->name()),
                $this->customerManagedPolicyArn((new DataWritePolicy())->name()),
                $this->customerManagedPolicyArn((new DumpsReadPolicy())->name()),
                $this->customerManagedPolicyArn((new AdminPolicy())->name()),
            ],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
