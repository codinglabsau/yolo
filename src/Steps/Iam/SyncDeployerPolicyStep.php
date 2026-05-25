<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Illuminate\Support\Arr;
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

        $policy = new DeployerPolicy();

        // Document drift (a YOLO upgrade that widened the deploy-time call set)
        // reconciled by creating a new policy version.
        if ($policy->exists() && ! Arr::get($options, 'dry-run')) {
            $policy->synchroniseDocument();
        }

        return $this->syncResource($policy, $options);
    }
}
