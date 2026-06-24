<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Concerns\ResolvesCanonicalHost;
use Codinglabs\Yolo\Concerns\ResolvesHttpsListener;
use Codinglabs\Yolo\Resources\ElbV2\RedirectListenerRule;

class SyncRedirectRuleStep implements ExecutesWebStep
{
    use ResolvesCanonicalHost;
    use ResolvesHttpsListener;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('domain')) {
            return StepResult::SKIPPED;
        }

        $apex = Manifest::apex();
        $hasWwwSibling = $this->hasWwwSibling($apex, Manifest::get('domain', $apex));

        $listener = $this->httpsListener();

        if ($listener === null) {
            // Same first-sync deferral as the forward rule: the `:443` listener is
            // bootstrapped earlier in this apply but is absent on the plan pass, so
            // report the rule as pending (rather than a self-pruning SKIPPED) when it
            // will be created this run, else defer. Only a redirecting apex/www app
            // has a rule to plan — a bare subdomain has nothing to redirect.
            if ((bool) Arr::get($options, 'dry-run') && $hasWwwSibling && $this->httpsListenerWillBeCreatedThisSync()) {
                $this->recordChange(Change::make('redirect rule', null, 'created'));

                return StepResult::WOULD_SYNC;
            }

            return StepResult::SKIPPED;
        }

        $rule = new RedirectListenerRule($listener['ListenerArn']);

        // A bare subdomain has no apex/www sibling to redirect. Tear down this
        // app's own redirect rule if an earlier apex/www config left one — it's
        // found by its Name tag, so no other host's rule is touched.
        if (! $hasWwwSibling) {
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
