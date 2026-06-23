<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;

class ExecuteDeployStepsStep implements LongRunning
{
    /**
     * Task-level CPU/memory the one-off deploy task runs with, overriding the
     * deploy group's task definition. The deploy group is usually a standalone
     * queue or scheduler (0.25 vCPU / 512 MiB) — too thin for migrations and
     * other deploy hooks, which are a one-shot, latency-sensitive cost on every
     * deploy. Bumping to 1 vCPU here speeds them up without growing the
     * long-running service. Must stay a valid Fargate CPU/memory pair: 1024 CPU
     * requires at least 2048 MiB.
     */
    protected const string DEPLOY_TASK_CPU = '1024';

    protected const string DEPLOY_TASK_MEMORY = '2048';

    public function __construct(protected string $environment) {}

    public function patienceMessage(): string
    {
        return 'Running deploy tasks (migrations, etc.) — this can take a few minutes';
    }

    public function __invoke(array $options = []): StepResult
    {
        $commands = Manifest::get('deploy', []);

        if (empty($commands)) {
            return StepResult::SKIPPED;
        }

        $script = "set -e\n" . implode("\n", $commands);

        // One-off deploy hooks run on the management-tier task def — a dedicated
        // scheduler if extracted, else a standalone queue, else web (see
        // Manifest::deployGroup). The container override's name MUST match that
        // group's container name (the task def names its container after the
        // group): ECS matches overrides by container name, so a mismatch silently
        // drops the command override and the task boots the role's default process
        // (e.g. supercronic) instead of the deploy script — never stopping.
        $group = Manifest::deployGroup();

        $cluster = (new EcsCluster())->name();

        $run = Aws::ecs()->runTask([
            'cluster' => $cluster,
            // The task definition family is the service name for this group (see EcsService).
            'taskDefinition' => (new EcsService($group))->name(),
            'launchType' => 'FARGATE',
            'count' => 1,
            'startedBy' => 'yolo-deploy',
            'overrides' => [
                'cpu' => self::DEPLOY_TASK_CPU,
                'memory' => self::DEPLOY_TASK_MEMORY,
                'containerOverrides' => [
                    [
                        'name' => $group->value,
                        'command' => ['sh', '-c', $script],
                    ],
                ],
            ],
            'networkConfiguration' => [
                'awsvpcConfiguration' => [
                    'subnets' => PublicSubnet::ids(),
                    'securityGroups' => [(new EcsTaskSecurityGroup())->arn()],
                    'assignPublicIp' => 'ENABLED',
                ],
            ],
        ]);

        if (empty($run['tasks'])) {
            throw new IntegrityCheckException(sprintf(
                'ECS RunTask returned no tasks. Failures: %s',
                json_encode($run['failures'] ?? [])
            ));
        }

        $taskArn = $run['tasks'][0]['taskArn'];

        Aws::waitFor(Aws::ecs(), 'TasksStopped', [
            'cluster' => $cluster,
            'tasks' => [$taskArn],
        ], timeout: 20 * 60, interval: 10);

        $stopped = Aws::ecs()->describeTasks([
            'cluster' => $cluster,
            'tasks' => [$taskArn],
        ])['tasks'][0];

        $exitCode = $stopped['containers'][0]['exitCode'] ?? null;

        if ($exitCode !== 0) {
            throw new IntegrityCheckException(sprintf(
                'Deploy task exited with code %s. Stop reason: %s. Container reason: %s',
                $exitCode ?? 'null',
                $stopped['stoppedReason'] ?? 'unknown',
                $stopped['containers'][0]['reason'] ?? 'none',
            ));
        }

        return StepResult::SUCCESS;
    }
}
