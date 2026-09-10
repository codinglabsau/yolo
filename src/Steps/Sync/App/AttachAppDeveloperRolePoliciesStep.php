<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;
use Codinglabs\Yolo\Resources\Iam\AppDeveloperRole;
use Codinglabs\Yolo\Resources\Iam\AppDataReadPolicy;
use Codinglabs\Yolo\Resources\Iam\AppObserverPolicy;
use Codinglabs\Yolo\Resources\Iam\AppDataWritePolicy;

class AttachAppDeveloperRolePoliciesStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        return $this->attachRolePolicies(
            (new AppDeveloperRole())->name(),
            [
                $this->customerManagedPolicyArn((new AppObserverPolicy())->name()),
                // The read document only exists when the app declares a bucket.
                ...Manifest::has('bucket') ? [$this->customerManagedPolicyArn((new AppDataReadPolicy())->name())] : [],
                $this->customerManagedPolicyArn((new AppDataWritePolicy())->name()),
            ],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
