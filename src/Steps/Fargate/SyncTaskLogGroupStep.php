<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;

class SyncTaskLogGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $logGroup = new TaskLogGroup();

        if ($logGroup->exists() && $logGroup->currentRetentionInDays() !== $logGroup->retentionInDays()) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            $logGroup->synchroniseRetention();
        }

        return $this->syncResource($logGroup, $options);
    }
}
