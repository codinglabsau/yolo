<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App\Solo;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesSoloStep;
use Codinglabs\Yolo\Concerns\ProvisionsScopedQueues;

class SyncQueueStep implements ExecutesSoloStep, Step
{
    use ProvisionsScopedQueues;

    public function __invoke(array $options): StepResult
    {
        return $this->syncScopedQueues(null, $options);
    }
}
