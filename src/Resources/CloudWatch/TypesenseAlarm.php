<?php

namespace Codinglabs\Yolo\Resources\CloudWatch;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Sns\SnsAlarmTopic;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * One Typesense health alarm, firing to the env SNS topic — parameterised so
 * the alarms step composes the quorum pair (healthy hosts < 3 warns, < 2 is
 * quorum lost) and the per-node memory alarms from one resource. Env-scoped:
 * the cluster is the environment's, whoever consumes it.
 */
class TypesenseAlarm implements Deletable, Resource, SynchronisesConfiguration
{
    /**
     * @param  array<int, array{Name: string, Value: string}>  $dimensions
     */
    public function __construct(
        protected string $suffix,
        protected string $description,
        protected string $namespace,
        protected string $metricName,
        protected array $dimensions,
        protected string $statistic,
        protected string $comparisonOperator,
        protected float $threshold,
        protected int $evaluationPeriods = 2,
        protected int $period = 60,
    ) {}

    use ResolvesTags;

    public function name(): string
    {
        return Helpers::keyedResourceName('typesense-' . $this->suffix, exclusive: false);
    }

    public function scope(): Scope
    {
        return Scope::Env;
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

        foreach ([
            'Threshold' => $this->threshold,
            'EvaluationPeriods' => $this->evaluationPeriods,
            'ComparisonOperator' => $this->comparisonOperator,
            'MetricName' => $this->metricName,
        ] as $field => $desired) {
            if (($live[$field] ?? null) != $desired) {
                $changes[] = Change::make($field, $live[$field] ?? null, $desired);
            }
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
            'Namespace' => $this->namespace,
            'MetricName' => $this->metricName,
            'Dimensions' => $this->dimensions,
            'Statistic' => $this->statistic,
            'Period' => $this->period,
            'EvaluationPeriods' => $this->evaluationPeriods,
            'Threshold' => $this->threshold,
            'ComparisonOperator' => $this->comparisonOperator,
            'TreatMissingData' => 'breaching',
            'AlarmActions' => [(new SnsAlarmTopic())->arn()],
            'OKActions' => [(new SnsAlarmTopic())->arn()],
            ...Aws::tags($this->tags()),
        ];
    }
}
