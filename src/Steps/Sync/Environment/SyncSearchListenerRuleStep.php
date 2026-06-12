<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElbV2\SearchListenerRule;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The :443 listener rule routing search.{domain} to the search target group.
 * On a greenfield plan the shared listener doesn't exist yet (it's
 * bootstrapped by the first app's cert), so the rule reports pending without
 * resolving it. Teardown deletes the rule by its stable Name tag.
 */
class SyncSearchListenerRuleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $state = Lifecycle::state(Service::TYPESENSE);

        if ($state === ServiceState::Retain) {
            return StepResult::SKIPPED;
        }

        try {
            $listenerArn = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443)['ListenerArn'];
        } catch (ResourceDoesNotExistException) {
            if ($state === ServiceState::Teardown) {
                return StepResult::SKIPPED; // no listener, no rule to tear down
            }

            Typesense::requireSearchHost();

            // The shared :443 listener is bootstrapped by the first app's cert
            // (the app tier, which runs after this one) — report the pending
            // rule on the plan and let the next sync create it.
            $this->recordChange(Change::make('search listener rule', null, 'created (:443 listener pending)'));

            return Arr::get($options, 'dry-run') ? StepResult::WOULD_CREATE : StepResult::SKIPPED;
        }

        $rule = new SearchListenerRule($listenerArn);

        if ($state === ServiceState::Teardown) {
            return $this->teardownRule($rule, $options);
        }

        Typesense::requireSearchHost();

        return $this->syncResource($rule, $options);
    }

    protected function teardownRule(SearchListenerRule $rule, array $options): StepResult
    {
        if (! $rule->exists()) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make($rule->name(), 'provisioned', null));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $rule->delete();

        return StepResult::DELETED;
    }
}
