<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Fargate\TaskLogGroup;

class SyncTaskDefinitionStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        Aws::ecs()->registerTaskDefinition(static::payload());

        return StepResult::SYNCED;
    }

    public static function payload(?string $imageTag = null): array
    {
        $port = (int) Manifest::get('tasks.web.port', 8000);
        $cpu = (string) Manifest::get('tasks.web.cpu', '512');
        $memory = (string) Manifest::get('tasks.web.memory', '1024');

        $image = $imageTag
            ? AwsLookups::ecrRepositoryUri() . ':' . $imageTag
            : Manifest::get('tasks.web.image', AwsLookups::ecrRepositoryUri() . ':latest');

        $taskRoleArn = Manifest::has('tasks.web.task-role')
            ? Manifest::get('tasks.web.task-role')
            : AwsLookups::ecsTaskRole()['Arn'];

        return [
            'family' => AwsLookups::ecsTaskFamily(),
            'networkMode' => 'awsvpc',
            'requiresCompatibilities' => ['FARGATE'],
            'cpu' => $cpu,
            'memory' => $memory,
            'executionRoleArn' => Manifest::get('tasks.web.execution-role', 'ecsTaskExecutionRole'),
            'taskRoleArn' => $taskRoleArn,
            'containerDefinitions' => [
                [
                    'name' => 'web',
                    'image' => $image,
                    'essential' => true,
                    'linuxParameters' => [
                        'initProcessEnabled' => true,
                    ],
                    'portMappings' => [
                        [
                            'containerPort' => $port,
                            'hostPort' => $port,
                            'protocol' => 'tcp',
                        ],
                    ],
                    'logConfiguration' => [
                        'logDriver' => 'awslogs',
                        'options' => [
                            'awslogs-group' => (new TaskLogGroup())->name(),
                            'awslogs-region' => Manifest::get('aws.region'),
                            'awslogs-stream-prefix' => 'web',
                        ],
                    ],
                ],
            ],
            'tags' => Aws::ecsTags(['Name' => AwsLookups::ecsTaskFamily()]),
        ];
    }
}
