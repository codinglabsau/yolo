<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App\Landlord;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\ProvisionsScopedQueues;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

class SyncQueueStep implements ExecutesMultitenancyStep
{
    use ProvisionsScopedQueues;

    public function __invoke(array $options): StepResult
    {
        return $this->syncScopedQueues('landlord', $options);
    }
}
