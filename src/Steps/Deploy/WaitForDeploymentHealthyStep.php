<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use RuntimeException;
use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Concerns\ResolvesServerGroups;

class WaitForDeploymentHealthyStep implements Step
{
    use ResolvesServerGroups;

    public function __construct(protected string $environment) {}

    /**
     * Wait until the NEW deployment's tasks are healthy in the load balancer —
     * i.e. the new version is actually serving — rather than for full "steady
     * state", which also waits out the old task's drain + stop (~90s of pure
     * cleanup that happens after users are already on the new code). A deploy
     * that returns before the new task is healthy can mask a crash-looping image,
     * so we still block here; we just stop once the new version is live.
     */
    public function __invoke(array $options): StepResult
    {
        // The health gate is ALB-based, so it only applies to the web service. A
        // deploy that targets only the queue/scheduler (--group) has no ALB rollout
        // to wait on — the ECS circuit breaker still auto-rolls-back a broken
        // headless deploy — so skip the wait entirely.
        if (! in_array(ServerGroup::WEB, $this->resolveServerGroups(Arr::get($options, 'group')), true)) {
            return StepResult::SKIPPED;
        }

        $cluster = (new EcsCluster())->name();
        $service = (new EcsService())->name();
        $targetGroupArn = (new TargetGroup())->arn();

        // The revision THIS deploy just registered — the family's latest ACTIVE
        // revision. Resolving it directly, rather than reading whatever's currently
        // PRIMARY, sidesteps the eventual-consistency lag where describeServices keeps
        // listing the OLD deployment as PRIMARY for a beat after updateService. Trusting
        // PRIMARY there matched the old, already-healthy task and declared success before
        // the new task even existed (the 0s "deploy" that masked a crash-looping image).
        $revision = Ecs::taskDefinition($service)['taskDefinitionArn'];

        $deadline = time() + 600;

        while (time() < $deadline) {
            $primary = Aws::ecs()->describeServices(['cluster' => $cluster, 'services' => [$service]])['services'][0];

            $deployment = collect($primary['deployments'])->firstWhere('taskDefinition', $revision);

            if (($deployment['rolloutState'] ?? null) === 'FAILED') {
                throw new RuntimeException('Deployment failed: ' . ($deployment['rolloutStateReason'] ?? 'rollout failed'));
            }

            $taskArns = Ecs::runningTasks($cluster, $service);

            $tasks = $taskArns === []
                ? []
                : Aws::ecs()->describeTasks(['cluster' => $cluster, 'tasks' => $taskArns])['tasks'];

            $targetHealth = Aws::elasticLoadBalancingV2()->describeTargetHealth([
                'TargetGroupArn' => $targetGroupArn,
            ])['TargetHealthDescriptions'];

            if (static::newTasksAreHealthy($tasks, $revision, (int) $primary['desiredCount'], $targetHealth)) {
                return StepResult::SUCCESS;
            }

            sleep(10);
        }

        throw new RuntimeException('Timed out waiting for the new deployment to become healthy.');
    }

    /**
     * Pure check: every running task on the new revision has a healthy target in
     * the load balancer. Old (draining) tasks are ignored — they're on the
     * previous revision — so this is true exactly when the new version is serving.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<int, array<string, mixed>>  $targetHealth
     */
    public static function newTasksAreHealthy(array $tasks, string $newRevision, int $desiredCount, array $targetHealth): bool
    {
        $newTaskIps = collect($tasks)
            ->filter(fn (array $task): bool => ($task['taskDefinitionArn'] ?? null) === $newRevision)
            ->map(fn (array $task) => data_get(
                collect($task['attachments'] ?? [])
                    ->flatMap(fn (array $attachment) => $attachment['details'] ?? [])
                    ->firstWhere('name', 'privateIPv4Address'),
                'value',
            ))
            ->filter()
            ->values();

        if ($newTaskIps->count() < $desiredCount) {
            return false;
        }

        $healthyIps = collect($targetHealth)
            ->filter(fn (array $target): bool => data_get($target, 'TargetHealth.State') === 'healthy')
            ->map(fn (array $target) => data_get($target, 'Target.Id'));

        return $newTaskIps->every(fn (string $ip) => $healthyIps->contains($ip));
    }
}
