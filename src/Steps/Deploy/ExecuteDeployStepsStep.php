<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;

class ExecuteDeployStepsStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(): StepResult
    {
        $commands = Manifest::get('deploy', []);

        if (empty($commands)) {
            return StepResult::SKIPPED;
        }

        $script = "set -e\n" . implode("\n", $commands);

        $cluster = (new EcsCluster())->name();

        $run = Aws::ecs()->runTask([
            'cluster' => $cluster,
            // The task definition family is the web service name (see EcsService).
            'taskDefinition' => (new EcsService())->name(),
            'launchType' => 'FARGATE',
            'count' => 1,
            'startedBy' => 'yolo-deploy',
            'overrides' => [
                'containerOverrides' => [
                    [
                        'name' => 'web',
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

        Aws::ecs()->waitUntil('TasksStopped', [
            'cluster' => $cluster,
            'tasks' => [$taskArn],
            '@waiter' => ['maxAttempts' => 120, 'delay' => 10],
        ]);

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
