<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\YoloObserver;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;
use Codinglabs\Yolo\Resources\Iam\YoloObserverRole;

/**
 * Attaches the read-only {@see YoloObserver} policy to the {@see YoloObserverRole},
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
            (new YoloObserverRole())->name(),
            [$this->customerManagedPolicyArn((new YoloObserver())->name())],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
