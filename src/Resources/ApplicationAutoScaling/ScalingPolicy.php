<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Web CPU target-tracking policy — the safety net beside {@see WebConcurrencyPolicy}:
 * catches a few heavy, low-rate requests that peg the CPU without raising request
 * concurrency. Both attach to the same {@see ScalableTarget}; App Auto Scaling takes
 * the max desired count across policies, so the two compose rather than fight.
 *
 * Also the web tier's only scale-in path. The concurrency policy and the burst policy
 * ({@see WebBurstPolicy}) are scale-out only, so the CPU scale-in alarm — 15 consecutive
 * minutes under target — is the one thing that can remove a task, and it can't trip
 * while a burst is still ramping. `scale-in-cooldown` therefore governs this policy alone.
 */
class ScalingPolicy implements TargetTrackingPolicy
{
    public function __construct(
        protected string $policyName,
        protected string $metricType,
        protected float $targetValue,
    ) {}

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
            'PolicyName' => $this->policyName,
            'ServiceNamespace' => ApplicationAutoScaling::SERVICE_NAMESPACE,
            'ResourceId' => ScalableTarget::resourceId(),
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
        return [
            'TargetValue' => $this->targetValue,
            'PredefinedMetricSpecification' => [
                'PredefinedMetricType' => $this->metricType,
            ],
            'ScaleOutCooldown' => static::scaleOutCooldown(),
            'ScaleInCooldown' => static::scaleInCooldown(),
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

        if ($currentTarget !== $this->targetValue) {
            $changes[] = Change::make("{$this->policyName} TargetValue", $currentTarget, $this->targetValue);
        }

        $currentMetric = $current['PredefinedMetricSpecification']['PredefinedMetricType'] ?? null;

        if ($currentMetric !== $this->metricType) {
            $changes[] = Change::make("{$this->policyName} metric", $currentMetric, $this->metricType);
        }

        $currentOut = isset($current['ScaleOutCooldown']) ? (int) $current['ScaleOutCooldown'] : null;

        if ($currentOut !== static::scaleOutCooldown()) {
            $changes[] = Change::make("{$this->policyName} ScaleOutCooldown", $currentOut, static::scaleOutCooldown());
        }

        $currentIn = isset($current['ScaleInCooldown']) ? (int) $current['ScaleInCooldown'] : null;

        if ($currentIn !== static::scaleInCooldown()) {
            $changes[] = Change::make("{$this->policyName} ScaleInCooldown", $currentIn, static::scaleInCooldown());
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        try {
            return ApplicationAutoScaling::scalingPolicy(ScalableTarget::resourceId(), $this->policyName);
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    public static function scaleOutCooldown(): int
    {
        return Helpers::validatePositiveInt(
            Manifest::get('tasks.web.autoscaling.scale-out-cooldown', 60),
            'tasks.web.autoscaling.scale-out-cooldown',
        );
    }

    public static function scaleInCooldown(): int
    {
        return Helpers::validatePositiveInt(
            Manifest::get('tasks.web.autoscaling.scale-in-cooldown', 300),
            'tasks.web.autoscaling.scale-in-cooldown',
        );
    }
}
