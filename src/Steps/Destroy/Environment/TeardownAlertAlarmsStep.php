<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatch\AlertAlarm;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncAlertAlarmsStep;

/**
 * Constructed bare: deletion is by name only, and the resources they watched
 * may already be gone.
 */
class TeardownAlertAlarmsStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $results = [];

        foreach (SyncAlertAlarmsStep::SUFFIXES as $suffix) {
            $results[] = $this->teardownResource(AlertAlarm::bare($suffix, Scope::Env), $options);
        }

        return $this->aggregateResults($results);
    }
}
