<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\WebWorkers;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The web service's **default** scaling policy: target-tracks in-flight request
 * **concurrency per task**, the leading signal for HTTP load. Scaling on the
 * requests a task is actively serving (`desired = ceil(active_requests /
 * workers_per_task)`) rather than trailing CPU means faster responses need fewer
 * tasks for the same traffic, and a spike is caught as it arrives instead of after
 * CPU has already climbed.
 *
 * Concurrency isn't a metric the ALB publishes, so it's derived with CloudWatch
 * metric math from two that it does (Little's Law, L = λ × W):
 *
 *     concurrency_per_task = (RequestCountPerTarget[Sum] / 60) × TargetResponseTime[Avg]
 *
 * RequestCountPerTarget is the per-target request count over the 1-minute ALB
 * period (÷60 → arrival rate per second); TargetResponseTime is the average time a
 * task spends serving a request (including any wait for a free FrankenPHP worker).
 * Their product is the average number of requests in flight on each task. Both are
 * dimensioned by this app's own target group, so the signal is per-app even though
 * the ALB is shared across the environment.
 *
 * The target is **derived from the pinned worker pool** ({@see WebWorkers}), not
 * hand-tuned from a load test: the task's worker count held at 70% utilisation,
 * leaving headroom for the within-minute peak and the cold start of the next task.
 * A 1 vCPU task → 16 workers → target ~11 concurrent. Sharing one count with the
 * runtime's `--workers` pin keeps the scale-out signal honest about the very pool it
 * scales — the policy can't aim at a capacity the task doesn't actually run.
 *
 * Composes with the CPU {@see ScalingPolicy} (the safety net for a few heavy,
 * low-rate requests that saturate CPU without raising concurrency): Application
 * Auto Scaling takes the max desired across all policies, so scale-out always wins
 * and the two never fight.
 *
 * Known dynamic: because the signal includes latency, a slow downstream dependency
 * (a struggling database) raises concurrency and scales the web tier out even when
 * adding tasks won't help — an exposure inherent to any latency-bearing signal. The
 * `max` bound caps the blast radius; the CPU policy doesn't (CPU stays low when the
 * stall is downstream), so `max` is the backstop there.
 *
 * Like {@see ScalingPolicy} this is a constructor-configured upsert reconciler
 * (PutScalingPolicy has no create/update split) and is dry-run honest — it diffs
 * the live policy and only writes on drift. It needs the ALB + target group
 * resolved to build its metric dimensions, so SyncScalingPoliciesStep only
 * constructs it once they exist (deferring it to the next sync on a greenfield
 * first sync, never throwing in the plan pass).
 */
class WebConcurrencyPolicy implements TargetTrackingPolicy
{
    /** Hold concurrency at 70% of worker capacity, leaving headroom for the in-minute peak. */
    private const float TARGET_UTILISATION = 0.7;

    /**
     * @param  string  $policyName  the App Auto Scaling policy name
     * @param  string  $loadBalancerDimension  the `app/{name}/{id}` ALB dimension value
     * @param  string  $targetGroupDimension  the `targetgroup/{name}/{id}` target-group dimension value
     */
    public function __construct(
        protected string $policyName,
        protected string $loadBalancerDimension,
        protected string $targetGroupDimension,
    ) {}

    /**
     * The desired concurrency per task — the pinned worker pool ({@see WebWorkers})
     * at 70% utilisation, floored to a whole request and never below 1 (a tiny task
     * still gets a meaningful target).
     */
    public function targetValue(): float
    {
        return max(1.0, floor(WebWorkers::count() * self::TARGET_UTILISATION));
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
     * The desired TargetTrackingScalingPolicyConfiguration: a metric-math metric
     * that turns this app's request rate and response time into per-task in-flight
     * concurrency. Only the expression returns data; the two source metrics feed it.
     *
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
                        // Little's Law: in-flight requests per task = arrival rate × latency.
                        // requests is the per-target count over the 60s ALB period → ÷60 gives
                        // requests/second; × the average service time gives concurrent requests.
                        'Expression' => '(requests / 60) * latency',
                        'Label' => 'In-flight requests per task',
                        'ReturnData' => true,
                    ],
                ],
            ],
            // Shared with the CPU policy so one cooldown setting governs web scaling:
            // fast out (60s), slow in (300s) to avoid flapping a cold-starting task.
            'ScaleOutCooldown' => ScalingPolicy::scaleOutCooldown(),
            'ScaleInCooldown' => ScalingPolicy::scaleInCooldown(),
        ];
    }

    /**
     * Diff the comparable fields of the live policy against the desired config. A
     * null $live reports every field as a change, so a fresh policy shows as a full
     * create.
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
            $changes[] = Change::make("{$this->policyName} TargetValue", $currentTarget, $this->targetValue());
        }

        $currentExpression = $this->expressionOf($current);
        $desiredExpression = $this->expressionOf($this->configuration());

        // Compare whitespace-insensitively: AWS may reformat the metric-math
        // expression on read-back (`(requests / 60) * latency` ⇄ `(requests/60)*latency`),
        // which would otherwise re-put the policy on every sync and never report
        // "Already in sync". Stripping whitespace still catches a real formula change.
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
     * The returning metric-math expression of a config, or null when absent — the
     * one comparable signature of the customized metric (dimensions carry live ALB
     * ids that aren't worth diffing field-by-field).
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

    /**
     * Drop all whitespace so an expression compares equal regardless of how AWS
     * spaced it on read-back. Null (no returning expression) stays null.
     */
    protected function normalise(?string $expression): ?string
    {
        return $expression === null ? null : (string) preg_replace('/\s+/', '', $expression);
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
}
