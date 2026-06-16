<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\AppObserversGroup;

/**
 * Provisions this app's observers grant group — membership grants read on this
 * app only (log content fenced to its group). Always provisioned, so a read
 * grant can name a single app.
 */
class SyncAppObserversGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AppObserversGroup(), $options);
    }
}
