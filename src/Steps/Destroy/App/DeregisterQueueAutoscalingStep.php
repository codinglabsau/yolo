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
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\QueueScaleToZeroBootstrap;

/**
 * Deregistering the scalable target cascades its policies, but the scale-to-zero
 * bootstrap's "queue has messages" alarm isn't owned by Application Auto Scaling
 * and must be deleted separately.
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
            $this->recordChange(Change::make(
                sprintf('%s autoscaling', (new EcsService(ServerGroup::QUEUE))->name()),
                sprintf('min %d / max %d', $live['min'], $live['max']),
                null,
            ));
        }

        if ($hasBootstrapAlarm) {
            $this->recordChange(Change::make($bootstrap->alarmName() . ' (scale-to-zero alarm)', 'provisioned', null));
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
