<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\YoloServiceProvider;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The queue/scheduler subclasses share the group-aware payload(). One image runs
 * every role; the ECS container `command` passes the role for the entrypoint to dispatch.
 */
class SyncTaskDefinitionStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');
        $live = $this->liveTaskDefinition((new EcsService($this->group()))->name());

        try {
            $desired = static::payload($this->group());
        } catch (ResourceDoesNotExistException $e) {
            // The roles / ECR the payload resolves aren't provisioned on a greenfield
            // plan pass — report pending; on apply they exist (registered earlier in
            // scope order), so a genuine miss is a hard fail.
            if ($dryRun) {
                $this->recordChange(Change::make('task definition', 'absent', 'new revision'));

                return StepResult::WOULD_SYNC;
            }

            throw $e;
        }

        // Which image runs is `yolo deploy`'s call: sync would render repo:latest and
        // re-register a throwaway revision after every deploy — one that, if adopted,
        // would swap the running image to :latest. Preserving the live image keeps a
        // no-op deploy→sync identical while a genuine infra change still re-registers
        // carrying the deployed image.
        if ($live !== null && isset($live['containerDefinitions'][0]['image'])) {
            $desired['containerDefinitions'][0]['image'] = $live['containerDefinitions'][0]['image'];
        }

        if ($live !== null && $this->matchesDesired(Arr::except($desired, ['tags']), $live)) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(
            'task definition',
            $live === null ? 'absent' : 'revision ' . ($live['revision'] ?? '?'),
            'new revision',
        ));

        if ($dryRun) {
            return StepResult::WOULD_SYNC;
        }

        Aws::ecs()->registerTaskDefinition($desired);

        return StepResult::SYNCED;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function liveTaskDefinition(string $family): ?array
    {
        try {
            return Ecs::taskDefinition($family);
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * A subset check, not equality: AWS enriches a registered task definition with
     * derived fields (revision, status, ARNs, container defaults) that would read
     * as phantom drift and re-register on every sync.
     *
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>  $live
     */
    protected function matchesDesired(array $desired, array $live): bool
    {
        foreach ($desired as $key => $value) {
            if (! array_key_exists($key, $live)) {
                return false;
            }

            if (is_array($value)) {
                if (! is_array($live[$key]) || ! $this->matchesDesired($value, $live[$key])) {
                    return false;
                }
            } elseif ((string) $value !== (string) $live[$key]) {
                return false;
            }
        }

        return true;
    }

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
        // at the same value, so they stay in lockstep.
        $family = (new EcsService($group))->name();

        // Derived from the same source as the entrypoint drain and supervisord's
        // stop waits, so a long drain or queue job isn't SIGKILLed mid-shutdown.
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
                    'command' => [$group->value],
                    'stopTimeout' => $stopTimeout,
                    'linuxParameters' => [
                        'initProcessEnabled' => true,
                    ],
                    ...$group->attachesToLoadBalancer() ? [
                        'portMappings' => [
                            [
                                'containerPort' => 8000,
                                'hostPort' => 8000,
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
                    ...static::burstEnvironment($group),
                ],
            ],
            'tags' => Aws::ecsTags(['Name' => $family]),
        ];
    }

    /**
     * The values the runtime {@see YoloServiceProvider} can't derive. YOLO_BURST_SERVICE's
     * presence is what tells it to publish, so burst needs no separate "enabled" flag;
     * same gate as the metrics Caddyfile and the PutMetricData grant, so they can't drift.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    protected static function burstEnvironment(ServerGroup $group): array
    {
        if ($group !== ServerGroup::WEB || ! Manifest::usesMetricsCaddyfile()) {
            return [];
        }

        $cpuUnits = (int) Manifest::get("{$group->manifestPrefix()}.cpu", $group->defaultCpu());

        return [
            'environment' => [
                ['name' => 'YOLO_BURST_SERVICE', 'value' => (new EcsService($group))->name()],
                // The Fargate microVM exposes more vCPUs than a fractional task is
                // throttled to, so the allocation can't be read back from cgroup/proc.
                ['name' => 'YOLO_BURST_CPU', 'value' => (string) ($cpuUnits / 1024)],
            ],
        ];
    }

    protected static function taskRoleArn(): string
    {
        return (new EcsTaskRole())->arn();
    }

    protected static function executionRoleArn(): string
    {
        return (new EcsExecutionRole())->arn();
    }
}
