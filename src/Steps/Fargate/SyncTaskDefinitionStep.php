<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Fargate\TaskLogGroup;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Resources\Fargate\EcrRepository;

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

        $ecrUri = (new EcrRepository())->uri();
        $image = $imageTag
            ? "$ecrUri:$imageTag"
            : Manifest::get('tasks.web.image', "$ecrUri:latest");

        $taskRoleArn = Manifest::has('tasks.web.task-role')
            ? Manifest::get('tasks.web.task-role')
            : (new EcsTaskRole())->arn();

        $executionRoleArn = Manifest::has('tasks.web.execution-role')
            ? Manifest::get('tasks.web.execution-role')
            : (new EcsExecutionRole())->arn();

        // Task definition family matches the keyed resource name (exclusive); same
        // value as EcsService::name(). Inlined rather than introducing a Resource
        // for the task definition (it's re-registered every sync — no exists/create).
        $family = Helpers::keyedResourceName(exclusive: true);

        return [
            'family' => $family,
            'networkMode' => 'awsvpc',
            'requiresCompatibilities' => ['FARGATE'],
            'cpu' => $cpu,
            'memory' => $memory,
            'executionRoleArn' => $executionRoleArn,
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
            'tags' => Aws::ecsTags(['Name' => $family]),
        ];
    }
}
