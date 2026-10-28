<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\AgentRole;
use Codinglabs\Yolo\Resources\Iam\AgentPolicy;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;

/** The agent policy alone — never the data or dump documents the human tiers attach. */
class AttachAgentRolePolicyStep implements Step
{
    use AttachesRolePolicies;

    public function __invoke(array $options): StepResult
    {
        return $this->attachRolePolicies(
            (new AgentRole())->name(),
            [$this->customerManagedPolicyArn((new AgentPolicy())->name())],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
