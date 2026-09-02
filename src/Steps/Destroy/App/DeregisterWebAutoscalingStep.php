<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;

/**
 * Application Auto Scaling cascades the delete to every target-tracking policy
 * and their alarms; the burst path's standalone alarm is {@see DeregisterWebBurstStep}'s.
 */
class DeregisterWebAutoscalingStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $target = new ScalableTarget(ServerGroup::WEB);
        $live = $target->current();

        if ($live === null) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make(
            sprintf('%s autoscaling', (new EcsService(ServerGroup::WEB))->name()),
            sprintf('min %d / max %d', $live['min'], $live['max']),
            null,
        ));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $target->deregister();

        return StepResult::DELETED;
    }
}
