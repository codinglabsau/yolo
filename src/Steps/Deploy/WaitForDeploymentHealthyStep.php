<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use RuntimeException;
use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\WaitReporter;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Concerns\ResolvesServerGroups;

class WaitForDeploymentHealthyStep implements LongRunning
{
    use ResolvesServerGroups;

    public function __construct(protected string $environment) {}

    public function patienceMessage(): string
    {
        return 'Waiting for the new deployment to become healthy in the load balancer — usually a minute or two';
    }

    /**
     * Waits for the new tasks to be healthy in the ALB rather than ECS "steady
     * state", which also waits out the old tasks' drain (~90s of cleanup after
     * users are already on the new code).
     */
    public function __invoke(array $options): StepResult
    {
        // ALB-based, so web only; the ECS circuit breaker still rolls back a
        // broken headless deploy.
        if (! in_array(ServerGroup::WEB, $this->resolveServerGroups(Arr::get($options, 'group')), true)) {
            return StepResult::SKIPPED;
        }

        $cluster = (new EcsCluster())->name();
        $service = (new EcsService())->name();
        $targetGroupArn = (new TargetGroup())->arn();

        // Resolved directly rather than from the PRIMARY deployment: describeServices
        // keeps listing the OLD deployment as PRIMARY for a beat after updateService,
        // which would match the already-healthy old task and declare success early.
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

            // Not an Aws::waitFor waiter, so the LongRunning heartbeat needs a manual tick.
            WaitReporter::poll();

            sleep(10);
        }

        throw new RuntimeException('Timed out waiting for the new deployment to become healthy.');
    }

    /**
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
