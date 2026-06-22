<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;
use Codinglabs\Yolo\Resources\Ecs\TypesenseService;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Resources\Ecr\TypesenseRepository;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TypesenseLogGroup;

/**
 * The single task-definition family every Typesense node runs
 * (yolo-{env}-typesense) — one family, three services, because the image
 * bakes the full peer list and each node identifies itself by matching a
 * local interface. Unlike app task definitions (where the image is deploy's
 * call), sync owns the image here: the desired revision pins the current
 * content tag, so a version bump or key rotation registers a new revision and
 * the nodes step rolls it through the cluster one node at a time.
 *
 * Teardown is a skip — task-definition revisions are registration history,
 * not standing infrastructure (the audit ignores them for the same reason).
 */
class SyncTypesenseTaskDefinitionStep implements SkippedByDeployCheck, Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (Lifecycle::state(Service::TYPESENSE) !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $family = (new TypesenseService(0))->taskDefinitionFamily();
        $live = $this->liveTaskDefinition($family);

        try {
            $desired = $this->payload($family);
        } catch (ResourceDoesNotExistException) {
            // The execution role / image tag aren't resolvable yet (a
            // greenfield plan pass) — report pending; on apply the earlier
            // steps have provisioned them, so a genuine miss is a hard fail.
            if ($dryRun) {
                $this->recordChange(Change::make('typesense task definition', 'absent', 'new revision'));

                return StepResult::WOULD_SYNC;
            }

            throw new ResourceDoesNotExistException('Cannot render the Typesense task definition — the execution role, image and log group must exist by now.');
        }

        if ($live !== null && $this->matchesDesired(Arr::except($desired, ['tags']), $live)) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(
            'typesense task definition',
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
     * @return array<string, mixed>
     */
    protected function payload(string $family): array
    {
        $tag = Typesense::imageTag();

        if ($tag === null) {
            // The admin key/version resolve earlier in this same pass — on the
            // plan they may not exist yet, which the caller reports as pending.
            throw new ResourceDoesNotExistException('Typesense image tag is not resolvable yet');
        }

        return [
            'family' => $family,
            'networkMode' => 'awsvpc',
            'requiresCompatibilities' => ['FARGATE'],
            'cpu' => (string) Typesense::cpu(),
            'memory' => (string) Typesense::memory(),
            // Typesense ships arm64 and the nodes run Graviton by default.
            'runtimePlatform' => [
                'cpuArchitecture' => 'ARM64',
                'operatingSystemFamily' => 'LINUX',
            ],
            // The shared env execution role covers the ECR pull + log writes;
            // there is no task role — Typesense calls no AWS APIs at runtime.
            'executionRoleArn' => (new EcsExecutionRole())->arn(),
            'containerDefinitions' => [
                [
                    'name' => 'typesense',
                    'image' => (new TypesenseRepository())->uri() . ':' . $tag,
                    'essential' => true,
                    'portMappings' => [
                        ['containerPort' => Typesense::API_PORT, 'hostPort' => Typesense::API_PORT, 'protocol' => 'tcp'],
                        ['containerPort' => Typesense::PEERING_PORT, 'hostPort' => Typesense::PEERING_PORT, 'protocol' => 'tcp'],
                    ],
                    // Typesense holds many concurrent connections + memory-mapped
                    // index files; the Fargate default nofile (1024) is too low.
                    'ulimits' => [
                        ['name' => 'nofile', 'softLimit' => 65535, 'hardLimit' => 65535],
                    ],
                    'logConfiguration' => [
                        'logDriver' => 'awslogs',
                        'options' => [
                            'awslogs-group' => (new TypesenseLogGroup())->name(),
                            'awslogs-region' => Manifest::get('region'),
                            'awslogs-stream-prefix' => 'typesense',
                        ],
                    ],
                ],
            ],
            'tags' => Aws::ecsTags(['Name' => $family]),
        ];
    }

    /**
     * Subset comparison — AWS enriches registered revisions with derived
     * fields we don't manage (see SyncTaskDefinitionStep::matchesDesired).
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
}
