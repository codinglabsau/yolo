<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Concerns\ResolvesHttpsListener;
use Codinglabs\Yolo\Resources\ElbV2\ForwardListenerRule;

class SyncForwardRuleStep implements ExecutesWebStep
{
    use ResolvesHttpsListener;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::hasDomain()) {
            return StepResult::SKIPPED;
        }

        $listener = $this->httpsListener();

        if ($listener === null) {
            // The `:443` listener is bootstrapped earlier in THIS apply, so it's
            // absent on the plan pass. If a certificate will be issued this run,
            // report the rule pending — a bare SKIPPED would prune the step, leave
            // the target group unattached, and ECS CreateService would reject the
            // web service. With no certificate reachable, genuinely defer.
            if ((bool) Arr::get($options, 'dry-run') && $this->httpsListenerWillBeCreatedThisSync()) {
                $this->recordChange(Change::make('forward rule', null, 'created'));

                return StepResult::WOULD_SYNC;
            }

            return StepResult::SKIPPED;
        }

        return $this->syncResource(new ForwardListenerRule($listener['ListenerArn']), $options);
    }
}
