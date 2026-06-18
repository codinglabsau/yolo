<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElbV2\ForwardListenerRule;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Removes this app's host→target-group forward rule from the shared :443
 * listener (found by its stable Name tag, so no sibling app's rule is touched).
 * Must run before the target group is deleted — a rule whose action references
 * a target group blocks the group's delete.
 */
class TeardownForwardRuleStep implements ExecutesWebStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        try {
            $listener = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            return StepResult::SKIPPED;
        }

        return $this->teardownResource(new ForwardListenerRule($listener['ListenerArn']), $options);
    }
}
