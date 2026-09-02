<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;
use Codinglabs\Yolo\Resources\Iam\AppObserverPolicy;

class AttachDeployerRolePoliciesStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        if (Helpers::githubRepository() === null) {
            return StepResult::SKIPPED;
        }

        // The pre-deploy `sync --check` gate plans account → environment → THIS
        // app, so the deployer gets the per-app observer policy: the same
        // unscopeable env-wide describes but with log *content* fenced to this
        // app's group, so a deploy grant can't read another app's logs (tags on
        // the bare log-group ARN stay readable for drift). Reconciled, not
        // additive, so an adopted role converges to exactly these two.
        return $this->reconcileRolePolicies(
            (new DeployerRole())->name(),
            [
                $this->customerManagedPolicyArn((new DeployerPolicy())->name()),
                $this->customerManagedPolicyArn((new AppObserverPolicy())->name()),
            ],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
