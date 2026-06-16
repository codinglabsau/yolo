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

        // DeployerPolicy = the deploy-time write/read grants. AppObserverPolicy =
        // the read surface the pre-deploy `sync --check` gate (EnsureInSyncStep)
        // needs: the gate plans account → environment → THIS app, so it reads
        // env-level resources + this app's, never a sibling app's. The per-app
        // observer policy gives exactly that — the same unscopeable env-wide
        // describes (AWS won't scope those) but with log *content* fenced to this
        // app's group, so a deploy grant can't read another app's logs. The gate
        // does read the task log group's TAGS to plan tag drift (granted on the
        // bare log-group ARN, see AppObserverPolicy), but never its content.
        //
        // Reconciled, not additive: swapping off the old env-wide ObserverPolicy
        // detaches it on the next sync, so an adopted deployer role converges to
        // exactly these two — no orphaned broad-read grant left behind.
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
