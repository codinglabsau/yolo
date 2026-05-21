<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Fargate\EcsService;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncEcsServiceStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $service = new EcsService();

        // Task definition revision adoption is owned by `yolo deploy`, not sync —
        // sync reconciles only the slow-moving service-level knobs.
        if ($service->exists() && $service->needsUpdate()) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            $service->update();
        }

        return $this->syncResource($service, $options);
    }
}
