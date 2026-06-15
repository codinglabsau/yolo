<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;

class AttachDeployerRolePoliciesStep implements Step
{
    use AttachesRolePolicies;

    /**
     * AWS-managed broad read-only policy, attached alongside the deploy-scoped
     * DeployerPolicy. The pre-deploy `sync:app --check` gate (EnsureAppInSyncStep)
     * runs the whole-app plan under the deployer role, which Describes/Gets/Lists
     * every service to compute drift — far more than `yolo deploy` writes. Granting
     * ReadOnlyAccess means a read the plan needs can never AccessDenied-abort a
     * deploy, now or as the sync surface grows. The one boundary it would breach —
     * s3:GetObject on every bucket — is clawed back by a Deny in DeployerPolicy.
     */
    protected const READ_ONLY_ACCESS_ARN = 'arn:aws:iam::aws:policy/ReadOnlyAccess';

    public function __invoke(array $options): StepResult
    {
        if (Helpers::githubRepository() === null) {
            return StepResult::SKIPPED;
        }

        return $this->attachRolePolicies(
            (new DeployerRole())->name(),
            [
                $this->customerManagedPolicyArn((new DeployerPolicy())->name()),
                self::READ_ONLY_ACCESS_ARN,
            ],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
