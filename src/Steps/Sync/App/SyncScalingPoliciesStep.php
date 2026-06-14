<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalingPolicy;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\TargetTrackingPolicy;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebConcurrencyPolicy;

/**
 * Reconciles the web service's target-tracking scaling policies onto its scalable
 * target. Two policies, both on by default once autoscaling is enabled:
 *
 *  - the request-concurrency policy ({@see WebConcurrencyPolicy}) — the default,
 *    leading signal, with its target derived from task memory (no load test); and
 *  - the CPU policy ({@see ScalingPolicy}, ECSServiceAverageCPUUtilization) — the
 *    safety net for load that pegs CPU without raising concurrency.
 *
 * Application Auto Scaling takes the max desired across both, so they compose
 * rather than fight. There is no per-app tuning to switch on: replacing the old
 * opt-in `request-count-per-target` policy, concurrency works out of the box.
 *
 * Reconciles desired against live: it upserts the policies the manifest wants and
 * prunes any live policy it no longer does — so the now-removed request-count
 * policy (and the alarms AWS generated for it) is deleted on the next sync.
 *
 * Skips on a greenfield first sync when the ECS service doesn't exist yet, and
 * silently defers the concurrency policy when the ALB / target group aren't
 * resolvable yet (it needs them to build its metric dimensions; it lands on the
 * next sync once they are — never throwing in the plan pass). The CPU policy has
 * no such dependency and is always present. When the whole autoscaling block is
 * removed this step no-ops — SyncScalableTargetStep deregisters the scalable
 * target, which cascades every policy and alarm.
 */
class SyncScalingPoliciesStep implements Step
{
    use RecordsChanges;

    protected const CPU_POLICY = 'cpu-scaling-policy';

    protected const CONCURRENCY_POLICY = 'concurrency-scaling-policy';

    public function __invoke(array $options): StepResult
    {
        // Autoscaling removed entirely → the scalable target is deregistered by
        // SyncScalableTargetStep, cascading every policy and alarm. Nothing to do.
        if (! Manifest::hasAutoscaling()) {
            return StepResult::SKIPPED;
        }

        if (! (new EcsService())->exists()) {
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
     * Live policies on the scalable target that the manifest no longer wants.
     * Diffed against desiredPolicyNames() — the manifest's intent — NOT policies(),
     * so the concurrency policy that's merely deferred (its ALB/TG not resolvable on
     * a greenfield sync) is never mistaken for one to prune.
     *
     * @return array<int, string>
     */
    public static function orphans(): array
    {
        return array_values(array_diff(
            ApplicationAutoScaling::policyNames(ScalableTarget::resourceId()),
            static::desiredPolicyNames(),
        ));
    }

    /**
     * The policy names the manifest intends to exist, independent of whether the
     * ALB / target group resolve right now — the prune set's source of truth. Both
     * the CPU and concurrency policies are always desired, so a briefly-deferred
     * concurrency policy is never pruned, while a leftover request-count policy
     * from before this change (not in this set) is.
     *
     * @return array<int, string>
     */
    public static function desiredPolicyNames(): array
    {
        return [
            Helpers::keyedResourceName(static::CPU_POLICY),
            Helpers::keyedResourceName(static::CONCURRENCY_POLICY),
        ];
    }

    /**
     * The scaling policies for the app: CPU always, plus request concurrency once
     * the ALB + target group are resolvable (they carry the live ids its metric
     * dimensions need).
     *
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

    /**
     * The request-concurrency policy, or null when the ALB / target group don't
     * exist yet (greenfield first sync) — deferred to the next sync rather than
     * failing the whole run.
     */
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
