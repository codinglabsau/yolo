<?php

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The queue service's target-tracking scaling policy, scaling on **backlog per
 * task** — `ApproximateNumberOfMessagesVisible / RunningTaskCount` via CloudWatch
 * metric math (no Lambda) — held at `tasks.queue.autoscaling.backlog-per-task` messages per
 * task. This is what scales the queue 1→N under load and back down to its floor.
 *
 * It deliberately can't scale 0→1: when the service is at zero the running-task
 * count is 0, so the division yields no data and target tracking has nothing to
 * act on. That zero-deadlock is broken by {@see QueueScaleToZeroBootstrap}'s
 * step-scaling alarm; this policy owns everything from 1 upward. App Auto Scaling
 * takes the max desired count across the two, so they compose rather than fight.
 *
 * Like ScalingPolicy this is a constructor-free upsert reconciler (PutScalingPolicy
 * has no create/update split) and is dry-run honest — it diffs the live policy and
 * only writes on drift.
 */
class QueueBacklogPolicy
{
    // The queue scales out fast (backlog hurts) and in slowly (avoid flapping a
    // cold-starting worker). Hardcoded — the backlog-per-task target is the tuning
    // lever; expose cooldowns only if a real need emerges.
    private const int SCALE_OUT_COOLDOWN = 60;

    private const int SCALE_IN_COOLDOWN = 120;

    public function policyName(): string
    {
        return Helpers::keyedResourceName('queue-backlog-policy');
    }

    public function targetValue(): float
    {
        return (float) Helpers::validatePositiveInt(
            Manifest::get('tasks.queue.autoscaling.backlog-per-task', 100),
            'tasks.queue.autoscaling.backlog-per-task',
        );
    }

    public function exists(): bool
    {
        return $this->current() !== null;
    }

    /**
     * Diff the live policy against the desired config and (only on drift, when
     * applying) upsert it.
     *
     * @return array<int, Change>
     */
    public function synchronise(bool $apply): array
    {
        $changes = $this->drift($this->current());

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        Aws::applicationAutoScaling()->putScalingPolicy([
            'PolicyName' => $this->policyName(),
            'ServiceNamespace' => ApplicationAutoScaling::SERVICE_NAMESPACE,
            'ResourceId' => ScalableTarget::resourceId(ServerGroup::QUEUE),
            'ScalableDimension' => ApplicationAutoScaling::SCALABLE_DIMENSION,
            'PolicyType' => 'TargetTrackingScaling',
            'TargetTrackingScalingPolicyConfiguration' => $this->configuration(),
        ]);

        return $changes;
    }

    /**
     * The desired TargetTrackingScalingPolicyConfiguration: a customised metric
     * that divides the queue's visible-message count by the running task count.
     *
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $queueName = Helpers::keyedResourceName();
        $cluster = (new EcsCluster())->name();
        $service = (new EcsService(ServerGroup::QUEUE))->name();

        return [
            'TargetValue' => $this->targetValue(),
            'CustomizedMetricSpecification' => [
                'Metrics' => [
                    [
                        'Id' => 'visible',
                        'MetricStat' => [
                            'Metric' => [
                                'Namespace' => 'AWS/SQS',
                                'MetricName' => 'ApproximateNumberOfMessagesVisible',
                                'Dimensions' => [['Name' => 'QueueName', 'Value' => $queueName]],
                            ],
                            'Stat' => 'Sum',
                        ],
                        'ReturnData' => false,
                    ],
                    [
                        'Id' => 'running',
                        'MetricStat' => [
                            'Metric' => [
                                // RunningTaskCount is published under Container Insights,
                                // which the cluster enables at create.
                                'Namespace' => 'ECS/ContainerInsights',
                                'MetricName' => 'RunningTaskCount',
                                'Dimensions' => [
                                    ['Name' => 'ClusterName', 'Value' => $cluster],
                                    ['Name' => 'ServiceName', 'Value' => $service],
                                ],
                            ],
                            'Stat' => 'Average',
                        ],
                        'ReturnData' => false,
                    ],
                    [
                        'Id' => 'backlog_per_task',
                        // Division by a zero running-task count yields no data, so
                        // this stays silent at zero — the step-scaling bootstrap owns 0→1.
                        'Expression' => 'visible / running',
                        'Label' => 'Backlog per task',
                        'ReturnData' => true,
                    ],
                ],
            ],
            'ScaleOutCooldown' => self::SCALE_OUT_COOLDOWN,
            'ScaleInCooldown' => self::SCALE_IN_COOLDOWN,
        ];
    }

    /**
     * Diff the comparable fields of the live policy against the desired config. A
     * null $live reports every field as a change, so a fresh policy shows as a
     * full create.
     *
     * @param  array<string, mixed>|null  $live
     * @return array<int, Change>
     */
    public function drift(?array $live): array
    {
        $current = $live['TargetTrackingScalingPolicyConfiguration'] ?? [];
        $changes = [];

        $currentTarget = isset($current['TargetValue']) ? (float) $current['TargetValue'] : null;

        if ($currentTarget !== $this->targetValue()) {
            $changes[] = Change::make('queue backlog TargetValue', $currentTarget, $this->targetValue());
        }

        $currentOut = isset($current['ScaleOutCooldown']) ? (int) $current['ScaleOutCooldown'] : null;

        if ($currentOut !== self::SCALE_OUT_COOLDOWN) {
            $changes[] = Change::make('queue backlog ScaleOutCooldown', $currentOut, self::SCALE_OUT_COOLDOWN);
        }

        $currentIn = isset($current['ScaleInCooldown']) ? (int) $current['ScaleInCooldown'] : null;

        if ($currentIn !== self::SCALE_IN_COOLDOWN) {
            $changes[] = Change::make('queue backlog ScaleInCooldown', $currentIn, self::SCALE_IN_COOLDOWN);
        }

        return $changes;
    }

    /**
     * The live policy, or null when it isn't registered yet.
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        try {
            return ApplicationAutoScaling::scalingPolicy(ScalableTarget::resourceId(ServerGroup::QUEUE), $this->policyName());
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
