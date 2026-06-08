<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Concerns\ResolvesCanonicalHost;
use Codinglabs\Yolo\Resources\ElbV2\RedirectListenerRule;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncRedirectRuleStep implements ExecutesWebStep
{
    use ResolvesCanonicalHost;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('apex') && ! Manifest::has('domain')) {
            return StepResult::SKIPPED;
        }

        try {
            $listener = ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);
        } catch (ResourceDoesNotExistException) {
            // no HTTPS listener yet (cert not issued) — defer
            return StepResult::SKIPPED;
        }

        $rule = new RedirectListenerRule($listener['ListenerArn']);
        $apex = Manifest::apex();

        // A bare subdomain has no apex/www sibling to redirect. Tear down this
        // app's own redirect rule if an earlier apex/www config left one — it's
        // found by its Name tag, so no other host's rule is touched.
        if (! $this->hasWwwSibling($apex, Manifest::get('domain', $apex))) {
            return $this->tearDown($rule, $options);
        }

        return $this->syncResource($rule, $options);
    }

    protected function tearDown(RedirectListenerRule $rule, array $options): StepResult
    {
        if (! $rule->exists()) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make('redirect rule', 'present', null));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $rule->delete();

        return StepResult::DELETED;
    }
}
