<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalingPolicy;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\TargetTrackingPolicy;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebConcurrencyPolicy;

/**
 * Two target-tracking policies on the web scalable target: request concurrency
 * ({@see WebConcurrencyPolicy}, the leading signal, target derived from task
 * memory) and CPU ({@see ScalingPolicy}, the safety net for load that pegs CPU
 * without raising concurrency). Application Auto Scaling takes the max desired
 * across every policy, so they compose rather than fight. Only the CPU policy
 * scales in — see {@see WebConcurrencyPolicy} for why the concurrency signal
 * can't be trusted to.
 *
 * Prunes any live policy outside YOLO's managed set — the union of this step's
 * two and the burst policy {@see SyncWebBurstStep} writes onto the same target —
 * so an out-of-band console policy can't silently skew scaling and the sibling's
 * burst policy is never touched. The set is sourced from the owning classes, so
 * a removed policy is pruned by absence, never by a remembered name.
 *
 * Never gates on the ECS service existing (a bare SKIPPED on the greenfield plan
 * pass would prune the step from apply). The concurrency policy is deferred
 * while the ALB / target group aren't resolvable — unresolvable on the
 * greenfield plan pass, resolvable by apply — and the managed set is keyed by
 * name independently of resolution, so a deferred policy is never pruned as an
 * orphan. When autoscaling is removed entirely, SyncScalableTargetStep's
 * deregistration cascades every policy and alarm.
 */
class SyncScalingPoliciesStep implements Step
{
    use RecordsChanges;

    protected const CPU_POLICY = 'cpu-scaling-policy';

    protected const CONCURRENCY_POLICY = 'concurrency-scaling-policy';

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::isAutoscaling()) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        $created = false;
        $synced = false;
        $deleted = false;

        foreach (static::policies() as $policy) {
            $existed = $policy->exists();
            $changes = $policy->synchronise(apply: ! $dryRun);

            $this->recordChanges($changes);

            if (! $existed) {
                $created = true;
            } elseif ($changes !== []) {
                $synced = true;
            }
        }

        foreach (static::orphans() as $orphan) {
            $this->recordChanges([Change::make($orphan, 'present', null)]);

            if (! $dryRun) {
                ApplicationAutoScaling::deleteScalingPolicy(ScalableTarget::resourceId(), $orphan);
            }

            $deleted = true;
        }

        if ($created) {
            return $dryRun ? StepResult::WOULD_CREATE : StepResult::CREATED;
        }

        if ($deleted) {
            return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
        }

        if ($synced) {
            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
        }

        return StepResult::SYNCED;
    }

    /**
     * @return array<int, string>
     */
    public static function orphans(): array
    {
        return array_values(array_diff(
            ApplicationAutoScaling::policyNames(ScalableTarget::resourceId()),
            static::managedPolicyNames(),
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function managedPolicyNames(): array
    {
        return [
            Helpers::keyedResourceName(static::CPU_POLICY),
            Helpers::keyedResourceName(static::CONCURRENCY_POLICY),
            (new WebBurstPolicy())->policyName(),
        ];
    }

    /**
     * @return array<int, TargetTrackingPolicy>
     */
    public static function policies(): array
    {
        $policies = [
            new ScalingPolicy(
                policyName: Helpers::keyedResourceName(static::CPU_POLICY),
                metricType: 'ECSServiceAverageCPUUtilization',
                targetValue: (float) Manifest::get('tasks.web.autoscaling.cpu-utilization', 65),
            ),
        ];

        if (($concurrency = static::concurrencyPolicy()) instanceof WebConcurrencyPolicy) {
            $policies[] = $concurrency;
        }

        return $policies;
    }

    /** Null while the ALB / target group aren't resolvable yet — deferred rather than fatal. */
    public static function concurrencyPolicy(): ?WebConcurrencyPolicy
    {
        try {
            $loadBalancerDimension = Dashboard::loadBalancerDimension((new LoadBalancer())->arn());
            $targetGroupDimension = Dashboard::targetGroupDimension((new TargetGroup())->arn());
        } catch (ResourceDoesNotExistException) {
            return null;
        }

        return new WebConcurrencyPolicy(
            policyName: Helpers::keyedResourceName(static::CONCURRENCY_POLICY),
            loadBalancerDimension: $loadBalancerDimension,
            targetGroupDimension: $targetGroupDimension,
        );
    }
}
