<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\AgentUser;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/** Creates the shared agent identity and pins its membership to the agents group; never its key. */
class SyncAgentUserStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AgentUser(), $options);
    }
}
