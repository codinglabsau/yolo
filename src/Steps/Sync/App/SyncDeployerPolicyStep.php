<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncDeployerPolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Helpers::githubRepository() === null) {
            return StepResult::SKIPPED;
        }

        // Tag drift and policy-document drift (a YOLO upgrade that widened the
        // deploy-time call set) both flow through syncResource — document drift
        // surfaces as a plan-time Change via SynchronisesPolicyDocument, so the
        // plan flags it and apply actually re-versions the policy.
        return $this->syncResource(new DeployerPolicy(), $options);
    }
}
