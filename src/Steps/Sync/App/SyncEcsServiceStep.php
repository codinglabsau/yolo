<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncEcsServiceStep implements Step
{
    use SynchronisesResource;

    /**
     * The workload group this step syncs a service for — web here; the
     * queue/scheduler subclasses override it. Standalone queue/scheduler steps are
     * only wired into sync:app when their block is present.
     */
    protected function group(): ServerGroup
    {
        return ServerGroup::WEB;
    }

    public function __invoke(array $options): StepResult
    {
        $service = new EcsService($this->group());

        // Task definition revision adoption is owned by `yolo deploy`, not sync —
        // sync reconciles only the slow-moving service-level knobs.
        if ($service->exists() && ($changes = $service->pendingChanges()) !== []) {
            $this->recordChanges($changes);

            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            $service->update();
        }

        return $this->syncResource($service, $options);
    }
}
