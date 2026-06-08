<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App\Landlord;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Sqs\Queue;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

class SyncQueueStep implements ExecutesMultitenancyStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new Queue(Helpers::keyedResourceName('landlord')), $options);
    }
}
