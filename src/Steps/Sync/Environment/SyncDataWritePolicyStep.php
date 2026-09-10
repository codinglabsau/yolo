<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\DataWritePolicy;

class SyncDataWritePolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new DataWritePolicy(), $options);
    }
}
