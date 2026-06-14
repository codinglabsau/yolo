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
 * The web service's CPU target-tracking scaling policy
 * (ECSServiceAverageCPUUtilization) — the safety net composed alongside the
 * default {@see WebConcurrencyPolicy}: it catches a few heavy, low-rate requests
 * that peg the CPU without raising request concurrency. A constructor-configured
 * reconciler around a predefined metric — PutScalingPolicy is a pure upsert, so
 * there's no create/update split. Dry-run honest: it reads the live policy, diffs
 * the comparable fields and only writes on drift.
 *
 * Both policies attach to the same {@see ScalableTarget}. Application Auto Scaling
 * takes the max desired count across every policy, so scale-out always wins and
 * the two metrics compose rather than fight.
 */
class ScalingPolicy implements TargetTrackingPolicy
{
    /**
     * @param  string  $policyName  the App Auto Scaling policy name
     * @param  string  $metricType  a predefined metric type (ECSServiceAverageCPUUtilization)
     * @param  float  $targetValue  the value target tracking holds the metric at
     */
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
     * Diff the live policy against the desired config and (only on drift, when
     * applying) upsert it. Returns the drift as Change[].
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
     * The desired TargetTrackingScalingPolicyConfiguration.
     *
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
     * Diff the comparable fields of the live policy against the desired config. A
     * null $live (policy absent) reports every field as a change, so a fresh
     * policy shows up in the plan as a full create.
     *
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
     * The live policy, or null when it isn't registered yet.
     *
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
