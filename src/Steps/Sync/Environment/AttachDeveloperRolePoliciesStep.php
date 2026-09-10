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
use Codinglabs\Yolo\Resources\Iam\DataWritePolicy;

/** Observer reads + both data documents; the infrastructure write policy never. */
class AttachDeveloperRolePoliciesStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        return $this->attachRolePolicies(
            (new DeveloperRole())->name(),
            [
                $this->customerManagedPolicyArn((new ObserverPolicy())->name()),
                $this->customerManagedPolicyArn((new DataReadPolicy())->name()),
                $this->customerManagedPolicyArn((new DataWritePolicy())->name()),
            ],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
