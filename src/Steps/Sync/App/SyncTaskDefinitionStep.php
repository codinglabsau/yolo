<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;

/**
 * Registers the web service's task definition. Standalone queue/scheduler
 * services register their own via SyncQueueTaskDefinitionStep /
 * SyncSchedulerTaskDefinitionStep — all three share payload(), which is
 * group-aware: the family, container name, role command, sizing and stop timeout
 * all follow the group. The one shared image runs every role; the ECS container
 * `command` passes the role (web|queue|scheduler) for the entrypoint to dispatch.
 */
class SyncTaskDefinitionStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        Aws::ecs()->registerTaskDefinition(static::payload($this->group()));

        return StepResult::SYNCED;
    }

    /**
     * The workload group this step registers a task definition for — web here;
     * the queue/scheduler subclasses override it. Standalone queue/scheduler steps
     * are only wired into sync:app when their block is present.
     */
    protected function group(): ServerGroup
    {
        return ServerGroup::WEB;
    }

    public static function payload(ServerGroup $group = ServerGroup::WEB, ?string $imageTag = null): array
    {
        $prefix = $group->manifestPrefix();
        $cpu = (string) Manifest::get("$prefix.cpu", $group->defaultCpu());
        $memory = (string) Manifest::get("$prefix.memory", $group->defaultMemory());

        $image = (new EcrRepository())->uri() . ':' . ($imageTag ?? 'latest');

        // The family is the service name — EcsService points its `taskDefinition`
        // at the same value, so they stay in lockstep. The task definition isn't its
        // own Resource (re-registered every sync — no exists/create distinction).
        $family = (new EcsService($group))->name();

        // ECS's SIGTERM-to-SIGKILL ceiling. Derived from the same source as the
        // entrypoint drain and supervisord's stop waits so a long drain or queue
        // job isn't cut short by SIGKILL mid-shutdown.
        $stopTimeout = ShutdownTimings::stopTimeoutFor($group);

        return [
            'family' => $family,
            'networkMode' => 'awsvpc',
            'requiresCompatibilities' => ['FARGATE'],
            'cpu' => $cpu,
            'memory' => $memory,
            'executionRoleArn' => static::executionRoleArn(),
            'taskRoleArn' => static::taskRoleArn(),
            'containerDefinitions' => [
                [
                    'name' => $group->value,
                    'image' => $image,
                    'essential' => true,
                    // The container command is the role — the entrypoint dispatches
                    // on it (web → supervisord, queue → worker, scheduler → cron).
                    'command' => [$group->value],
                    'stopTimeout' => $stopTimeout,
                    'linuxParameters' => [
                        'initProcessEnabled' => true,
                    ],
                    // Only the web container is reached over the network (the ALB);
                    // queue and scheduler are headless and map no port.
                    ...$group->attachesToLoadBalancer() ? [
                        'portMappings' => [
                            [
                                'containerPort' => (int) Manifest::get('tasks.web.port', 8000),
                                'hostPort' => (int) Manifest::get('tasks.web.port', 8000),
                                'protocol' => 'tcp',
                            ],
                        ],
                    ] : [],
                    'logConfiguration' => [
                        'logDriver' => 'awslogs',
                        'options' => [
                            'awslogs-group' => (new TaskLogGroup())->name(),
                            'awslogs-region' => Manifest::get('region'),
                            'awslogs-stream-prefix' => $group->value,
                        ],
                    ],
                ],
            ],
            'tags' => Aws::ecsTags(['Name' => $family]),
        ];
    }

    protected static function taskRoleArn(): string
    {
        return Manifest::has('tasks.web.task-role')
            ? Manifest::get('tasks.web.task-role')
            : (new EcsTaskRole())->arn();
    }

    protected static function executionRoleArn(): string
    {
        return Manifest::has('tasks.web.execution-role')
            ? Manifest::get('tasks.web.execution-role')
            : (new EcsExecutionRole())->arn();
    }
}
