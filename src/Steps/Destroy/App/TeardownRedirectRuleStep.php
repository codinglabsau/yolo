<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElbV2\RedirectListenerRule;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Removes this app's apex↔www redirect rule from the shared :443 listener
 * (matched by its stable Name tag). A bare-subdomain app never had one, so the
 * rule is simply absent and the step skips.
 */
class TeardownRedirectRuleStep implements ExecutesWebStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        try {
            $listener = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            return StepResult::SKIPPED;
        }

        return $this->teardownResource(new RedirectListenerRule($listener['ListenerArn']), $options);
    }
}
