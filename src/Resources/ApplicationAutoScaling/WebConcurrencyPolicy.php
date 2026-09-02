<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\WebConcurrency;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The web service's default policy: target-tracks in-flight requests per task, the
 * leading signal for HTTP load (a spike is caught as it arrives, not after CPU has
 * climbed). The ALB doesn't publish concurrency, so it's derived by metric math from
 * two metrics it does, dimensioned by this app's own target group so the signal stays
 * per-app on the shared ALB.
 *
 * The target derives from the task's pinned concurrency ceiling ({@see WebConcurrency})
 * rather than a load test, so the policy can't aim at a capacity the task doesn't run.
 * It resolves through WebConcurrency rather than the Octane pool because a classic-mode
 * tier has no resident workers — its ceiling is the thread maximum ({@see WebThreads}).
 *
 * Known dynamic: the signal includes latency, so a slow downstream dependency scales
 * the web tier out even when more tasks won't help. `max` is the backstop — the CPU
 * policy won't cap it, since CPU stays low when the stall is downstream.
 *
 * Needs the ALB + target group for its dimensions, so SyncScalingPoliciesStep only
 * constructs it once they exist — deferring on a greenfield first sync rather than
 * throwing in the plan pass.
 */
class WebConcurrencyPolicy implements TargetTrackingPolicy
{
    /** Headroom for the within-minute peak and the next task's cold start. */
    private const float TARGET_UTILISATION = 0.7;

    public function __construct(
        protected string $policyName,
        protected string $loadBalancerDimension,
        protected string $targetGroupDimension,
    ) {}

    public function targetValue(): float
    {
        return max(1.0, floor(WebConcurrency::ceiling() * self::TARGET_UTILISATION));
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
            'TargetValue' => $this->targetValue(),
            'CustomizedMetricSpecification' => [
                'Metrics' => [
                    [
                        'Id' => 'requests',
                        'MetricStat' => [
                            'Metric' => [
                                'Namespace' => 'AWS/ApplicationELB',
                                'MetricName' => 'RequestCountPerTarget',
                                'Dimensions' => [
                                    ['Name' => 'TargetGroup', 'Value' => $this->targetGroupDimension],
                                ],
                            ],
                            'Stat' => 'Sum',
                        ],
                        'ReturnData' => false,
                    ],
                    [
                        'Id' => 'latency',
                        'MetricStat' => [
                            'Metric' => [
                                'Namespace' => 'AWS/ApplicationELB',
                                'MetricName' => 'TargetResponseTime',
                                'Dimensions' => [
                                    ['Name' => 'TargetGroup', 'Value' => $this->targetGroupDimension],
                                    ['Name' => 'LoadBalancer', 'Value' => $this->loadBalancerDimension],
                                ],
                            ],
                            'Stat' => 'Average',
                        ],
                        'ReturnData' => false,
                    ],
                    [
                        'Id' => 'concurrency',
                        // Little's Law: (requests over the 60s ALB period ÷ 60) × avg service time = in-flight per task.
                        'Expression' => '(requests / 60) * latency',
                        'Label' => 'In-flight requests per task',
                        'ReturnData' => true,
                    ],
                ],
            ],
            // Shared with the CPU policy so one cooldown setting governs web scaling.
            'ScaleOutCooldown' => ScalingPolicy::scaleOutCooldown(),
            'ScaleInCooldown' => ScalingPolicy::scaleInCooldown(),
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
            $changes[] = Change::make("{$this->policyName} TargetValue", $currentTarget, $this->targetValue());
        }

        $currentExpression = $this->expressionOf($current);
        $desiredExpression = $this->expressionOf($this->configuration());

        // AWS may reformat the expression's whitespace on read-back, which would re-put on every sync.
        if ($this->normalise($currentExpression) !== $this->normalise($desiredExpression)) {
            $changes[] = Change::make("{$this->policyName} metric", $currentExpression, $desiredExpression);
        }

        $currentOut = isset($current['ScaleOutCooldown']) ? (int) $current['ScaleOutCooldown'] : null;

        if ($currentOut !== ScalingPolicy::scaleOutCooldown()) {
            $changes[] = Change::make("{$this->policyName} ScaleOutCooldown", $currentOut, ScalingPolicy::scaleOutCooldown());
        }

        $currentIn = isset($current['ScaleInCooldown']) ? (int) $current['ScaleInCooldown'] : null;

        if ($currentIn !== ScalingPolicy::scaleInCooldown()) {
            $changes[] = Change::make("{$this->policyName} ScaleInCooldown", $currentIn, ScalingPolicy::scaleInCooldown());
        }

        return $changes;
    }

    /**
     * The expression is the one comparable signature of the customized metric — the
     * dimensions carry live ALB ids not worth diffing field-by-field.
     *
     * @param  array<string, mixed>  $config
     */
    protected function expressionOf(array $config): ?string
    {
        foreach ($config['CustomizedMetricSpecification']['Metrics'] ?? [] as $metric) {
            if (($metric['ReturnData'] ?? false) === true) {
                return $metric['Expression'] ?? null;
            }
        }

        return null;
    }

    protected function normalise(?string $expression): ?string
    {
        return $expression === null ? null : (string) preg_replace('/\s+/', '', $expression);
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
}
