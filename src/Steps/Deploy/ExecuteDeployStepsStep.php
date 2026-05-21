<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

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

        $run = Aws::ecs()->runTask([
            'cluster' => AwsLookups::ecsClusterName(),
            'taskDefinition' => AwsLookups::ecsTaskFamily(),
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
                    'subnets' => AwsLookups::publicSubnetIds(),
                    'securityGroups' => [AwsLookups::ecsTaskSecurityGroup()['GroupId']],
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
            'cluster' => AwsLookups::ecsClusterName(),
            'tasks' => [$taskArn],
            '@waiter' => ['maxAttempts' => 120, 'delay' => 10],
        ]);

        $stopped = Aws::ecs()->describeTasks([
            'cluster' => AwsLookups::ecsClusterName(),
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
