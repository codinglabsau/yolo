<?php

declare(strict_types=1);

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

    protected function group(): ServerGroup
    {
        return ServerGroup::WEB;
    }

    public function __invoke(array $options): StepResult
    {
        $service = new EcsService($this->group());

        // Task-definition revision adoption is `yolo deploy`'s, not sync's.
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
