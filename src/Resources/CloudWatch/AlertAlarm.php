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
 * Parameterised so the alert steps compose the whole family from one resource. The
 * `alert-` segment marks alarms that mean a human should look, distinct from the
 * autoscaling alarms that live in ALARM as part of their control loop. Missing data
 * is not breaching: an absent metric stream (idle env, torn-down source) must not page.
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

    /** Teardown addresses alarms purely by name, so the metric fields are irrelevant. */
    public static function bare(string $suffix, Scope $alarmScope): self
    {
        return new self(
            suffix: $suffix,
            description: 'retired',
            alarmScope: $alarmScope,
            comparisonOperator: 'GreaterThanThreshold',
            threshold: 0,
            evaluationPeriods: 1,
        );
    }

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
     * putMetricAlarm is a pure upsert, so drift re-puts the whole payload.
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

        // Dimensions carry ALB/target-group ARN suffixes that change when those are
        // recreated under the same alarm name — unreconciled, the alarm points at a
        // dead metric and notBreaching keeps it green forever.
        if ($this->metrics === []) {
            foreach ([
                'Dimensions' => $this->dimensions,
                'Period' => $this->period,
                'Statistic' => $this->statistic,
            ] as $field => $desired) {
                if (($live[$field] ?? null) != $desired) {
                    $changes[] = Change::make($field, 'drift', 'reconciled');
                }
            }
        } elseif ($this->metricsSignature($live['Metrics'] ?? []) != $this->metricsSignature($this->metrics)) {
            $changes[] = Change::make('Metrics', 'drift', 'reconciled (metric math)');
        }

        // An alarm keeps firing to the ARN it was created with, so AlarmActions is
        // drift too. On the plan pass of the sync that creates the topic it doesn't
        // exist yet — absence proves the re-point is pending, so record the change
        // without resolving; the apply pass runs after the topic step.
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
     * Only authored fields are compared, so keys AWS adds on read can't fake drift.
     *
     * @param  array<int, array<string, mixed>>  $metrics
     * @return array<string, array<string, mixed>>
     */
    protected function metricsSignature(array $metrics): array
    {
        $signatures = [];

        foreach ($metrics as $entry) {
            $signatures[$entry['Id'] ?? ''] = [
                'expression' => $entry['Expression'] ?? null,
                'returnData' => $entry['ReturnData'] ?? null,
                'metric' => $entry['MetricStat']['Metric'] ?? null,
                'period' => $entry['MetricStat']['Period'] ?? null,
                'stat' => $entry['MetricStat']['Stat'] ?? null,
            ];
        }

        return $signatures;
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
