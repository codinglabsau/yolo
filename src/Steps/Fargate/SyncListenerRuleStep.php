<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Fargate\ListenerRule;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncListenerRuleStep implements ExecutesWebStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('apex') && ! Manifest::has('domain')) {
            return StepResult::SKIPPED;
        }

        try {
            $listener = AwsLookups::loadBalancerListenerOnPort(443);
        } catch (ResourceDoesNotExistException) {
            // no HTTPS listener yet (cert not issued) — defer
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new ListenerRule($listener['ListenerArn']), $options);
    }
}
