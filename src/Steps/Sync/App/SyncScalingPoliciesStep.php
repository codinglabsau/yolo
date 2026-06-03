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

/**
 * Reconciles the web service's target-tracking scaling policies onto its scalable
 * target. The CPU policy (ECSServiceAverageCPUUtilization) is always present when
 * autoscaling is enabled — it works without any load-test tuning. The
 * request-count policy (ALBRequestCountPerTarget) is added only once a
 * tasks.web.autoscaling.request-count-per-target value is set, since its target
 * is the per-target request plateau that has to come from a load test.
 *
 * Reconciles desired against live: it upserts the policies the manifest wants and
 * prunes any live policy it no longer does — removing request-count-per-target
 * deletes that policy (and the alarms AWS generated for it) on the next sync.
 *
 * Skips on a greenfield first sync when the ECS service doesn't exist yet, and
 * silently drops the request-count policy when the ALB / target group aren't
 * resolvable yet (it lands on the next sync once they are). When the whole
 * autoscaling block is removed this step no-ops — SyncScalableTargetStep
 * deregisters the scalable target, which cascades every policy and alarm.
 */
class SyncScalingPoliciesStep implements Step
{
    use RecordsChanges;

    protected const CPU_POLICY = 'cpu-scaling-policy';

    protected const REQUEST_COUNT_POLICY = 'request-count-scaling-policy';

    public function __invoke(array $options): StepResult
    {
        // Autoscaling removed entirely → the scalable target is deregistered by
        // SyncScalableTargetStep, cascading every policy and alarm. Nothing to do.
        if (! Manifest::has('tasks.web.autoscaling')) {
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
     * so a request-count policy that's merely deferred (its ResourceLabel not
     * resolvable on a greenfield sync) is never mistaken for one to prune.
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
     * ALB / target group resolve right now — the prune set's source of truth.
     *
     * @return array<int, string>
     */
    public static function desiredPolicyNames(): array
    {
        $names = [Helpers::keyedResourceName(static::CPU_POLICY)];

        if (Manifest::has('tasks.web.autoscaling.request-count-per-target')) {
            $names[] = Helpers::keyedResourceName(static::REQUEST_COUNT_POLICY);
        }

        return $names;
    }

    /**
     * The scaling policies for the app: CPU always, plus request-count once its
     * target value is configured and the ALB + target group are resolvable.
     *
     * @return array<int, ScalingPolicy>
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

        if (Manifest::has('tasks.web.autoscaling.request-count-per-target') && ($resourceLabel = static::resourceLabel()) !== null) {
            $policies[] = new ScalingPolicy(
                policyName: Helpers::keyedResourceName(static::REQUEST_COUNT_POLICY),
                metricType: 'ALBRequestCountPerTarget',
                targetValue: (float) Manifest::get('tasks.web.autoscaling.request-count-per-target'),
                resourceLabel: $resourceLabel,
            );
        }

        return $policies;
    }

    /**
     * {alb-arn-suffix}/{tg-arn-suffix} — tells ALBRequestCountPerTarget which
     * target group's per-target request rate to track. Null when the ALB or
     * target group don't exist yet (greenfield first sync), so the request-count
     * policy is deferred to the next sync rather than failing the whole run.
     */
    public static function resourceLabel(): ?string
    {
        try {
            return sprintf(
                '%s/%s',
                Dashboard::loadBalancerDimension((new LoadBalancer())->arn()),
                Dashboard::targetGroupDimension((new TargetGroup())->arn()),
            );
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
