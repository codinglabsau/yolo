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
        if (! Manifest::has('domain')) {
            return StepResult::SKIPPED;
        }

        $listener = $this->httpsListener();

        if ($listener === null) {
            // The `:443` listener is bootstrapped earlier in THIS apply
            // (SyncHttpsListenerStep) but doesn't exist on the plan pass, which runs
            // before anything is created. If it's going to be created (the cert is
            // issued), report the rule as pending so the step survives to apply — a
            // bare SKIPPED here is pruned from the apply pass (two-pass contract), so
            // the rule never gets created, the target group is left unattached, and
            // ECS CreateService rejects the web service. With no issued cert the
            // listener won't be created this run either, so genuinely defer.
            if ((bool) Arr::get($options, 'dry-run') && $this->httpsListenerWillBeCreatedThisSync()) {
                $this->recordChange(Change::make('forward rule', null, 'created'));

                return StepResult::WOULD_SYNC;
            }

            return StepResult::SKIPPED;
        }

        return $this->syncResource(new ForwardListenerRule($listener['ListenerArn']), $options);
    }
}
