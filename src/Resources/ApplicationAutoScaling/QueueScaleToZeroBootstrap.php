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
 * Breaks the queue's 0→1 deadlock. {@see QueueBacklogPolicy} scales on
 * messages-per-running-task, which is undefined at zero running tasks (division
 * by zero → no data), so target tracking can never lift a scaled-to-zero queue
 * off the floor. This pairs a step-scaling policy with a CloudWatch alarm on the
 * queue's visible-message count: the instant a message lands while at zero, the
 * alarm sets desired count to exactly one, and target tracking owns it from
 * there.
 *
 * ExactCapacity 1 (not +1) is deliberate — App Auto Scaling takes the max desired
 * across policies, so asserting "at least one while there's backlog" never fights
 * the backlog policy's higher number and never ratchets up on a long backlog.
 *
 * Only provisioned when the queue's floor is zero (`tasks.queue.min: 0`); a queue
 * with a standing floor never sits at zero, so it needs no bootstrap. Both the
 * policy and the alarm are pure upserts, so this is a reconciler, not a Resource.
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
     * Provision (or confirm) the step policy + its alarm. The config is static, so
     * drift is simply "either piece is missing"; reported as a Change so the sync
     * step renders WOULD_CREATE / CREATED and survives the only-pending filter.
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

        // PutMetricAlarm ignores Tags when updating an existing alarm, so reconcile
        // the ownership markers explicitly (TagResource works on an existing alarm) —
        // so the alarm reads as `ok` in yolo audit rather than `rogue`.
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
     * App-scoped ownership tags, matching what a Resource's ResolvesTags would
     * stamp. The yolo:environment baseline is added at write time by Aws::tags().
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
