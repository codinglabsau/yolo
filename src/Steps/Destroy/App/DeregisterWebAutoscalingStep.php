<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;

/**
 * Deregisters the web service's Application Auto Scaling scalable target.
 * Application Auto Scaling cascades the delete to every target-tracking policy
 * on it (CPU + request-count) and the alarms those policies created — so this
 * one call clears the bulk of web autoscaling. The burst step policy + its
 * standalone alarm are handled separately ({@see DeregisterWebBurstStep}).
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

        $this->recordChange(Change::make('web autoscaling', sprintf('%d-%d', $live['min'], $live['max']), null));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $target->deregister();

        return StepResult::DELETED;
    }
}
