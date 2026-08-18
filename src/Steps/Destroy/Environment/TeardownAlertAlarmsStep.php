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
 * Tears down the env alert alarms. Deletion is by name only, so the alarms
 * are constructed bare — no live lookups of the load balancer, cache or
 * database they watched, which may already be gone by this point in the
 * destroy. Absent alarms (e.g. the database set on an env that never
 * declared one) skip for free.
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
