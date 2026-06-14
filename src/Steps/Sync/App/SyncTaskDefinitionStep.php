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
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

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
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');
        $live = $this->liveTaskDefinition((new EcsService($this->group()))->name());

        try {
            $desired = static::payload($this->group());
        } catch (ResourceDoesNotExistException $e) {
            // The roles / ECR the payload resolves aren't provisioned yet (a
            // greenfield plan pass) — so the task definition can't exist either.
            // Report it pending on the plan; on apply the deps exist (registered
            // earlier in scope order) so a genuine miss is a hard fail.
            if ($dryRun) {
                $this->recordChange(Change::make('task definition', 'absent', 'new revision'));

                return StepResult::WOULD_SYNC;
            }

            throw $e;
        }

        // Which image runs is `yolo deploy`'s call, not sync's. Deploy pins the app
        // version (repo:<version>); sync would otherwise render repo:latest and so
        // re-register a throwaway revision on every run after a deploy (the image
        // tag is the only field that differs) — and that revision, if ever adopted,
        // would swap the running image to :latest. So preserve the live revision's
        // image: a no-op deploy→sync renders an identical document, and a genuine
        // infra-field change still re-registers carrying the deployed image.
        if ($live !== null && isset($live['containerDefinitions'][0]['image'])) {
            $desired['containerDefinitions'][0]['image'] = $live['containerDefinitions'][0]['image'];
        }

        // The registered revision already renders the desired payload — nothing to
        // do, so the step is pruned before apply and a clean app reports "Already
        // in sync" instead of registering a no-op revision every time (LPX-646).
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
     * The latest active revision of the family, or null when none is registered
     * yet (a first sync).
     *
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
     * Whether every attribute YOLO sets in the desired payload is present and equal
     * in the live revision. A subset check (not equality) because AWS enriches a
     * registered task definition with derived fields (revision, status, ARNs,
     * container defaults) we don't manage — comparing the whole document would read
     * all of that as phantom drift and re-register on every sync.
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
        // at the same value, so they stay in lockstep. The task definition isn't
        // modelled as a Resource (no taggable ARN to own); SyncTaskDefinitionStep
        // reconciles it diff-first against the latest registered revision.
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
                    // Burst scaling reads FrankenPHP's worker metrics; switch the metrics
                    // endpoint on additively through Octane's Caddyfile {$CADDY_GLOBAL_OPTIONS}
                    // placeholder — Caddy reads it from the OS env (the app .env never reaches
                    // Caddy's process), and it's never a Caddyfile takeover. Web task only, set
                    // wherever web autoscaling is (a no-op in classic mode, which ignores it).
                    ...$group === ServerGroup::WEB && Manifest::has('tasks.web.autoscaling') ? [
                        'environment' => [
                            ['name' => 'CADDY_GLOBAL_OPTIONS', 'value' => 'servers { metrics }'],
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
        return (new EcsTaskRole())->arn();
    }

    protected static function executionRoleArn(): string
    {
        return (new EcsExecutionRole())->arn();
    }
}
