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
 * Scales the queue 1→N on backlog per task (visible messages / running tasks via
 * metric math). It can't scale 0→1: at zero running tasks the division yields no
 * data, so {@see QueueScaleToZeroBootstrap} owns 0→1. App Auto Scaling takes the
 * max desired count across the two, so they compose rather than fight.
 */
class QueueBacklogPolicy
{
    // Out fast (backlog hurts), in slowly (avoid flapping a cold-starting worker).
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
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        // Scale on the base (default-tier) queue: a `high` tier is meant to stay
        // near-empty, so the base backlog is the throughput signal. (A multi-tenant
        // standalone queue has no single aggregate metric here.)
        $queueName = Helpers::defaultQueueName();
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
                                // RunningTaskCount only exists with Container Insights on (enabled at cluster create).
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
