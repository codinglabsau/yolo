<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;

class AttachDeployerRolePoliciesStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        if (Helpers::githubRepository() === null) {
            return StepResult::SKIPPED;
        }

        // DeployerPolicy = the deploy-time write/read grants. ObserverPolicy = the
        // env-shared read-only surface the pre-deploy `sync --check` gate
        // (EnsureInSyncStep) needs to inspect the whole stack — attaching it means
        // the deployer inherits exactly that read without a new direct grant, and
        // never the broad blast radius of AWS-managed ReadOnlyAccess.
        return $this->attachRolePolicies(
            (new DeployerRole())->name(),
            [
                $this->customerManagedPolicyArn((new DeployerPolicy())->name()),
                $this->customerManagedPolicyArn((new ObserverPolicy())->name()),
            ],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
