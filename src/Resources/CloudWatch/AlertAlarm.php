<?php

namespace Codinglabs\Yolo\Resources\CloudWatch;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Sns\SnsAlarmTopic;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * One "bad things are happening" alarm, firing to the env SNS topic —
 * parameterised so the alert steps compose the whole family (ALB 5xx, cache
 * pressure, database saturation, per-app error rate) from one resource. The
 * `alert-` name segment marks the family: these are the alarms that mean a
 * human should look, distinct from the autoscaling alarms that live in ALARM
 * as part of their control loop. Missing data is not breaching by default —
 * an absent metric stream (idle env, torn-down source) must not page.
 *
 * Takes either a plain metric (namespace/metric/statistic) or a `metrics`
 * math array (e.g. an error *rate* over two metrics); putMetricAlarm is a
 * pure upsert either way, so drift re-puts the whole payload.
 */
class AlertAlarm implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    /**
     * @param  array<int, array{Name: string, Value: string}>  $dimensions
     * @param  array<int, array<string, mixed>>  $metrics
     */
    public function __construct(
        protected string $suffix,
        protected string $description,
        protected Scope $alarmScope,
        protected string $comparisonOperator,
        protected float $threshold,
        protected int $evaluationPeriods,
        protected ?string $namespace = null,
        protected ?string $metricName = null,
        protected array $dimensions = [],
        protected string $statistic = 'Average',
        protected int $period = 300,
        protected ?int $datapointsToAlarm = null,
        protected array $metrics = [],
    ) {}

    public function name(): string
    {
        return $this->keyedName('alert-' . $this->suffix);
    }

    public function scope(): Scope
    {
        return $this->alarmScope;
    }

    public function exists(): bool
    {
        try {
            CloudWatch::alarm($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return CloudWatch::alarm($this->name())['AlarmArn'];
    }

    public function create(): void
    {
        Aws::cloudWatch()->putMetricAlarm($this->payload());
    }

    public function delete(): void
    {
        Aws::cloudWatch()->deleteAlarms(['AlarmNames' => [$this->name()]]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseCloudWatchTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Reconcile the alarm's managed fields — putMetricAlarm is a pure upsert,
     * so drift re-puts the whole payload.
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $live = CloudWatch::alarm($this->name());

        $changes = [];

        foreach (array_filter([
            'Threshold' => $this->threshold,
            'EvaluationPeriods' => $this->evaluationPeriods,
            'ComparisonOperator' => $this->comparisonOperator,
            'MetricName' => $this->metricName,
            'DatapointsToAlarm' => $this->datapointsToAlarm,
        ], fn (mixed $desired): bool => $desired !== null) as $field => $desired) {
            if (($live[$field] ?? null) != $desired) {
                $changes[] = Change::make($field, $live[$field] ?? null, $desired);
            }
        }

        // Re-point the alarm after a topic rename: an alarm keeps firing to
        // whatever ARN it was created with, so AlarmActions is drift like any
        // other field. Resolving the desired ARN needs the topic to exist —
        // on the plan pass of the very sync that creates it (greenfield, or a
        // rename), absence itself proves the re-point is pending, so record
        // the change without resolving; the apply pass runs after the topic
        // step and resolves it (the two-pass contract).
        try {
            $desiredActions = [(new SnsAlarmTopic())->arn()];

            if (($live['AlarmActions'] ?? []) != $desiredActions) {
                $changes[] = Change::make('AlarmActions', $live['AlarmActions'][0] ?? null, $desiredActions[0]);
            }
        } catch (ResourceDoesNotExistException) {
            $changes[] = Change::make('AlarmActions', $live['AlarmActions'][0] ?? null, (new SnsAlarmTopic())->name());
        }

        if ($changes !== [] && $apply) {
            Aws::cloudWatch()->putMetricAlarm($this->payload());
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'AlarmName' => $this->name(),
            'AlarmDescription' => $this->description,
            ...$this->metrics !== [] ? [
                'Metrics' => $this->metrics,
            ] : [
                'Namespace' => $this->namespace,
                'MetricName' => $this->metricName,
                'Dimensions' => $this->dimensions,
                'Statistic' => $this->statistic,
                'Period' => $this->period,
            ],
            'EvaluationPeriods' => $this->evaluationPeriods,
            ...$this->datapointsToAlarm !== null ? ['DatapointsToAlarm' => $this->datapointsToAlarm] : [],
            'Threshold' => $this->threshold,
            'ComparisonOperator' => $this->comparisonOperator,
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [(new SnsAlarmTopic())->arn()],
            'OKActions' => [(new SnsAlarmTopic())->arn()],
            ...Aws::tags($this->tags()),
        ];
    }
}
