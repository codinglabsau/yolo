<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElbV2\SearchListenerRule;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Attaches the app's `search.{apex}` listener rule to the env HTTPS listener,
 * forwarding to the shared Meilisearch target group. App-scoped ingress to
 * env-shared compute — the same additive attachment as the app's own forward
 * rule. Mirrors SyncForwardRuleStep.
 */
class SyncSearchRuleStep implements ExecutesWebStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::scoutDriver() !== 'meilisearch') {
            return StepResult::SKIPPED;
        }

        try {
            $listener = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            // no HTTPS listener yet (cert not issued) — defer
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new SearchListenerRule($listener['ListenerArn']), $options);
    }
}
