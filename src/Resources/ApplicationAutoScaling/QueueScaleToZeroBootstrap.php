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
 * alarm sets desired count to ExactCapacity 1 — not +1, so it never fights the
 * backlog policy's higher number or ratchets up on a long backlog. Only needed
 * when `tasks.queue.autoscaling.min` is 0.
 */
class QueueScaleToZeroBootstrap
{
    private const int COOLDOWN = 60;

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
     * The config is static, so drift is simply "either piece is missing".
     *
     * @return array<int, Change>
     */
    public function synchronise(bool $apply): array
    {
        $changes = [];

        if (! $this->policyExists()) {
            $changes[] = Change::make('queue scale-to-zero policy', null, $this->policyName());
        }

        if (! $this->alarmExists()) {
            $changes[] = Change::make('queue scale-to-zero alarm', null, $this->alarmName());
        }

        if ($changes === [] || ! $apply) {
            return $changes;
        }

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
            'Dimensions' => [['Name' => 'QueueName', 'Value' => Helpers::keyedResourceName()]],
            'EvaluationPeriods' => 1,
            'MetricName' => 'ApproximateNumberOfMessagesVisible',
            'Namespace' => 'AWS/SQS',
            'Period' => 60,
            'Statistic' => 'Maximum',
            'Threshold' => 0,
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [$policyArn],
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
        try {
            CloudWatch::alarm($this->alarmName());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
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
