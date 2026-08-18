<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatch\AlertAlarm;

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

    private const array SUFFIXES = [
        'alb-5xx',
        'valkey-memory',
        'valkey-evictions',
        'database-cpu',
        'database-memory',
        'database-connections',
        'database-buffer-cache',
    ];

    public function __invoke(array $options): StepResult
    {
        $results = [];

        foreach (self::SUFFIXES as $suffix) {
            $results[] = $this->teardownResource(new AlertAlarm(
                suffix: $suffix,
                description: 'retired',
                alarmScope: Scope::Env,
                comparisonOperator: 'GreaterThanThreshold',
                threshold: 0,
                evaluationPeriods: 1,
            ), $options);
        }

        foreach ([
            StepResult::WOULD_DELETE, StepResult::DELETED,
        ] as $significant) {
            if (in_array($significant, $results, true)) {
                return $significant;
            }
        }

        return StepResult::SKIPPED;
    }
}
