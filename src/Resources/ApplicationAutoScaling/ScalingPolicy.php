<?php

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A target-tracking scaling policy on the web service's scalable target. A
 * constructor-configured reconciler shared by the request-count and CPU policies —
 * PutScalingPolicy is a pure upsert, so there's no create/update split. Dry-run
 * honest: it reads the live policy, diffs the comparable fields and only writes on
 * drift.
 *
 * Both policies attach to the same {@see ScalableTarget}. App Auto Scaling takes
 * the max desired count across every policy, so scale-out always wins and the two
 * metrics compose rather than fight.
 */
class ScalingPolicy
{
    /**
     * @param  string  $policyName  the App Auto Scaling policy name
     * @param  string  $metricType  a predefined metric type (ALBRequestCountPerTarget / ECSServiceAverageCPUUtilization)
     * @param  float  $targetValue  the value target tracking holds the metric at
     * @param  string|null  $resourceLabel  required by ALBRequestCountPerTarget ({alb-suffix}/{tg-suffix}); null otherwise
     */
    public function __construct(
        protected string $policyName,
        protected string $metricType,
        protected float $targetValue,
        protected ?string $resourceLabel = null,
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
            'PredefinedMetricSpecification' => array_filter([
                'PredefinedMetricType' => $this->metricType,
                'ResourceLabel' => $this->resourceLabel,
            ], fn ($value) => $value !== null),
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

        $currentLabel = $current['PredefinedMetricSpecification']['ResourceLabel'] ?? null;

        if ($currentLabel !== $this->resourceLabel) {
            $changes[] = Change::make("{$this->policyName} ResourceLabel", $currentLabel, $this->resourceLabel);
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
