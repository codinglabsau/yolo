<?php

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Breaks the queue's 0→1 deadlock: {@see QueueBacklogPolicy} divides by running
 * tasks, so it has no data at zero. A step-scaling policy on a visible-messages
 * alarm over the same {@see QueueBacklog} queues sets desired count to ExactCapacity 1 — not +1, so it never fights the
 * backlog policy's higher number or ratchets up on a long backlog. Only needed
 * when `tasks.queue.autoscaling.min` is 0.
 */
class QueueScaleToZeroBootstrap
{
    private const int COOLDOWN = 60;

    private const int PERIOD = 60;

    public function policyName(): string
    {
        return Helpers::keyedResourceName('queue-bootstrap-policy');
    }

    public function alarmName(): string
    {
        return Helpers::keyedResourceName('queue-has-messages');
    }

    public function exists(): bool
    {
        return $this->policyExists() && $this->alarmExists();
    }

    /**
     * Drift is either piece missing, or the alarm watching a different queue set than
     * the manifest declares (a tenant added under dedicated isolation).
     *
     * @return array<int, Change>
     */
    public function synchronise(bool $apply): array
    {
        $changes = [];

        if (! $this->policyExists()) {
            $changes[] = Change::make('queue scale-to-zero policy', null, $this->policyName());
        }

        $alarm = $this->currentAlarm();

        if ($alarm === null) {
            $changes[] = Change::make('queue scale-to-zero alarm', null, $this->alarmName());
        } elseif (QueueBacklog::liveQueueNames($alarm) !== QueueBacklog::queueNames()) {
            $changes[] = Change::make(
                'queue scale-to-zero alarm queues',
                implode(', ', QueueBacklog::liveQueueNames($alarm)) ?: null,
                implode(', ', QueueBacklog::queueNames()),
            );
        }

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        // PutScalingPolicy is an upsert, so re-putting an existing policy is the
        // cheapest way to hold its ARN for the alarm action.
        $policyArn = Aws::applicationAutoScaling()->putScalingPolicy([
            'PolicyName' => $this->policyName(),
            'ServiceNamespace' => ApplicationAutoScaling::SERVICE_NAMESPACE,
            'ResourceId' => ScalableTarget::resourceId(ServerGroup::QUEUE),
            'ScalableDimension' => ApplicationAutoScaling::SCALABLE_DIMENSION,
            'PolicyType' => 'StepScaling',
            'StepScalingPolicyConfiguration' => [
                'AdjustmentType' => 'ExactCapacity',
                'Cooldown' => self::COOLDOWN,
                'MetricAggregationType' => 'Maximum',
                'StepAdjustments' => [
                    ['MetricIntervalLowerBound' => 0, 'ScalingAdjustment' => 1],
                ],
            ],
        ])['PolicyARN'];

        Aws::cloudWatch()->putMetricAlarm([
            'ActionsEnabled' => true,
            'AlarmName' => $this->alarmName(),
            'AlarmDescription' => 'Lifts the queue off zero when a message arrives. Created by yolo CLI',
            'ComparisonOperator' => 'GreaterThanThreshold',
            'EvaluationPeriods' => 1,
            'Threshold' => 0,
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [$policyArn],
            ...$this->alarmMetric(),
            ...Aws::tags($this->tags()),
        ]);

        // PutMetricAlarm ignores Tags on an existing alarm; without this audit reads it as rogue.
        Aws::synchroniseCloudWatchTags(
            CloudWatch::alarm($this->alarmName())['AlarmArn'],
            $this->tags(),
            apply: true,
        );

        return $changes;
    }

    /**
     * A single queue is watched directly; a dedicated-isolation set is watched as a
     * metric-math SUM, the same term the backlog policy divides by running tasks. The
     * two forms are mutually exclusive on PutMetricAlarm, so this returns exactly one.
     *
     * @return array<string, mixed>
     */
    protected function alarmMetric(): array
    {
        $queues = QueueBacklog::queues();

        if (count($queues) === 1) {
            return [
                'MetricName' => 'ApproximateNumberOfMessagesVisible',
                'Namespace' => 'AWS/SQS',
                'Dimensions' => [['Name' => 'QueueName', 'Value' => reset($queues)]],
                'Period' => self::PERIOD,
                'Statistic' => 'Maximum',
            ];
        }

        return [
            'Metrics' => [
                ...QueueBacklog::metricStats('Maximum', self::PERIOD),
                [
                    'Id' => 'visible',
                    'Expression' => QueueBacklog::expression(),
                    'Label' => 'Visible messages',
                    'ReturnData' => true,
                ],
            ],
        ];
    }

    public function policyExists(): bool
    {
        try {
            ApplicationAutoScaling::scalingPolicy(ScalableTarget::resourceId(ServerGroup::QUEUE), $this->policyName());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function alarmExists(): bool
    {
        return $this->currentAlarm() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function currentAlarm(): ?array
    {
        try {
            return CloudWatch::alarm($this->alarmName());
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * Mirrors ResolvesTags; yolo:environment is added at write time by Aws::tags().
     *
     * @return array<string, string>
     */
    public function tags(): array
    {
        return [
            'Name' => $this->alarmName(),
            'yolo:scope' => Scope::App->value,
            'yolo:app' => Manifest::name(),
        ];
    }
}
