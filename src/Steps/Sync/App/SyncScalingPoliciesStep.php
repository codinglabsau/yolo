<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalingPolicy;

/**
 * Reconciles the web service's target-tracking scaling policies onto its scalable
 * target. The CPU policy (ECSServiceAverageCPUUtilization) is always present when
 * autoscaling is enabled — it works without any load-test tuning. The
 * request-count policy (ALBRequestCountPerTarget) is added only once a
 * tasks.web.autoscaling.request-count-per-target value is set, since its target
 * is the per-target request plateau that has to come from a load test.
 *
 * Skips on a greenfield first sync when the ECS service doesn't exist yet, and
 * silently drops the request-count policy when the ALB / target group aren't
 * resolvable yet (it lands on the next sync once they are).
 */
class SyncScalingPoliciesStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (! (new EcsService())->exists()) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        $created = false;
        $synced = false;

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

        if ($created) {
            return $dryRun ? StepResult::WOULD_CREATE : StepResult::CREATED;
        }

        if ($synced) {
            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
        }

        return StepResult::SYNCED;
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
                policyName: Helpers::keyedResourceName('cpu-scaling-policy'),
                metricType: 'ECSServiceAverageCPUUtilization',
                targetValue: (float) Manifest::get('tasks.web.autoscaling.cpu-utilization', 65),
            ),
        ];

        if (Manifest::has('tasks.web.autoscaling.request-count-per-target') && ($resourceLabel = static::resourceLabel()) !== null) {
            $policies[] = new ScalingPolicy(
                policyName: Helpers::keyedResourceName('request-count-scaling-policy'),
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
