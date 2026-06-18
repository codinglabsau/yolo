<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\QueueScaleToZeroBootstrap;

/**
 * Deregisters the standalone queue's scalable target — cascading its backlog
 * target-tracking policy and the scale-to-zero bootstrap step policy — and then
 * deletes the scale-to-zero bootstrap's standalone "queue has messages" alarm,
 * which Application Auto Scaling does not own and so won't cascade.
 */
class DeregisterQueueAutoscalingStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        $target = new ScalableTarget(ServerGroup::QUEUE);
        $live = $target->current();

        $bootstrap = new QueueScaleToZeroBootstrap();
        $hasBootstrapAlarm = $bootstrap->alarmExists();

        if ($live === null && ! $hasBootstrapAlarm) {
            return StepResult::SKIPPED;
        }

        if ($live !== null) {
            $this->recordChange(Change::make('queue autoscaling', sprintf('%d-%d', $live['min'], $live['max']), null));
        }

        if ($hasBootstrapAlarm) {
            $this->recordChange(Change::make('queue scale-to-zero alarm', 'provisioned', null));
        }

        if ($dryRun) {
            return StepResult::WOULD_DELETE;
        }

        if ($live !== null) {
            $target->deregister();
        }

        if ($hasBootstrapAlarm) {
            Aws::cloudWatch()->deleteAlarms(['AlarmNames' => [$bootstrap->alarmName()]]);
        }

        return StepResult::DELETED;
    }
}
