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
 * Reconciles the web service's target-tracking scaling policies onto its scalable
 * target. Two policies, both on by default once autoscaling is enabled:
 *
 *  - the request-concurrency policy ({@see WebConcurrencyPolicy}) — the default,
 *    leading signal, with its target derived from task memory (no load test); and
 *  - the CPU policy ({@see ScalingPolicy}, ECSServiceAverageCPUUtilization) — the
 *    safety net for load that pegs CPU without raising concurrency.
 *
 * Application Auto Scaling takes the max desired across every policy on the target,
 * so they compose rather than fight. There is no per-app tuning to switch on —
 * concurrency works out of the box.
 *
 * Reconciles desired against live: it upserts its two policies and prunes any live
 * policy that ISN'T part of YOLO's managed set for this scalable target — the union
 * of this step's policies and the burst policy ({@see WebBurstPolicy}), which a
 * sibling step ({@see SyncWebBurstStep}) writes onto the same target. Pruning
 * against that full set — not just this step's two — keeps burst while still
 * reconciling away anything that doesn't belong: a policy added out-of-band (e.g.
 * via the console) that would silently skew scaling since AAS maxes across every
 * policy, or one a past YOLO version created and no longer manages. The set is
 * sourced from the owning policy classes, so no retired policy name is ever
 * hardcoded — a removed policy is pruned because it's absent from the current set,
 * not because YOLO remembers it. `yolo sync --check` surfaces the prune as drift
 * before it's applied.
 *
 * Never gates on the ECS service existing — on a greenfield PLAN pass nothing
 * exists yet, and a bare SKIPPED there would prune the step from the apply pass
 * (two-pass contract); the apply runs after SyncEcsServiceStep and
 * SyncScalableTargetStep have created what the policies attach to. The
 * concurrency policy is silently deferred while the ALB / target group aren't
 * resolvable (it needs them to build its metric dimensions — unresolvable on the
 * greenfield plan pass, resolvable by the time the apply pass reaches this step,
 * so it lands in the same sync). The CPU policy has no such dependency and is
 * always present. The managed set is keyed by name
 * independently of resolution, so a merely-deferred concurrency policy is never
 * mistaken for an orphan. When the whole autoscaling block is removed this step
 * no-ops — SyncScalableTargetStep deregisters the scalable target, which cascades
 * every policy and alarm.
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
     * Live policies on the scalable target that aren't part of YOLO's managed set —
     * the live set minus managedPolicyNames(). Catches a policy added out-of-band
     * (e.g. via the console, which would otherwise skew autoscaling silently) and
     * one a past YOLO version left behind, while never touching the burst policy a
     * sibling step owns on the same target.
     *
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
     * Every policy name YOLO manages on the web scalable target — the prune set's
     * source of truth. The union of this step's policies (CPU + concurrency) and the
     * burst policy a sibling step ({@see SyncWebBurstStep}) writes onto the same
     * target. Keyed by name independently of whether each resolves or exists right
     * now, so a briefly-deferred concurrency policy is never pruned; sourced from the
     * owning classes, so no retired policy name is ever hardcoded.
     *
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
