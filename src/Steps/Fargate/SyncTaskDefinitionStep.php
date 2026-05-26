<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Fargate\EcsService;
use Codinglabs\Yolo\Resources\Fargate\TargetGroup;
use Codinglabs\Yolo\Resources\Fargate\TaskLogGroup;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Resources\Fargate\EcrRepository;

class SyncTaskDefinitionStep implements Step
{
    // Headroom on top of the drain so the server can finish in-flight requests
    // after the lame-duck sleep before ECS escalates to SIGKILL. The default
    // drain (10s) + this margin lands on AWS's own 30s default, so apps that
    // don't tune the deregistration delay see no change.
    protected const STOP_TIMEOUT_DRAIN_MARGIN = 20;

    // Fargate caps the container stopTimeout at 120s.
    protected const MAX_STOP_TIMEOUT = 120;

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

        // The family is the web service name — EcsService points its `taskDefinition`
        // at the same value, so they stay in lockstep. The task definition isn't its
        // own Resource (re-registered every sync — no exists/create distinction).
        $family = (new EcsService())->name();

        // Give ECS enough time between SIGTERM and SIGKILL to cover the entrypoint's
        // lame-duck drain (the deregistration-delay window) plus in-flight request
        // completion — otherwise a long drain would be cut short by SIGKILL.
        $stopTimeout = min(
            (new TargetGroup())->deregistrationDelay() + self::STOP_TIMEOUT_DRAIN_MARGIN,
            self::MAX_STOP_TIMEOUT,
        );

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
                    'stopTimeout' => $stopTimeout,
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
