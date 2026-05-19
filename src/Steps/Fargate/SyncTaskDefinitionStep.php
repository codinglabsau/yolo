<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

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

    public static function payload(): array
    {
        $port = (int) Manifest::get('tasks.web.port', 8000);
        $cpu = (string) Manifest::get('tasks.web.cpu', '512');
        $memory = (string) Manifest::get('tasks.web.memory', '1024');

        return [
            'family' => AwsResources::ecsTaskFamily(),
            'networkMode' => 'awsvpc',
            'requiresCompatibilities' => ['FARGATE'],
            'cpu' => $cpu,
            'memory' => $memory,
            'executionRoleArn' => Manifest::get('tasks.web.execution-role', 'ecsTaskExecutionRole'),
            ...Manifest::has('tasks.web.task-role') ? ['taskRoleArn' => Manifest::get('tasks.web.task-role')] : [],
            'containerDefinitions' => [
                [
                    'name' => 'web',
                    'image' => Manifest::get('tasks.web.image', AwsResources::ecrRepositoryUri() . ':latest'),
                    'essential' => true,
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
                            'awslogs-group' => SyncTaskLogGroupStep::logGroupName(),
                            'awslogs-region' => Manifest::get('aws.region'),
                            'awslogs-stream-prefix' => 'web',
                        ],
                    ],
                ],
            ],
            'tags' => Aws::ecsTags(['Name' => AwsResources::ecsTaskFamily()]),
        ];
    }
}
