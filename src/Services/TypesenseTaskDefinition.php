<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Ecs\TypesenseService;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Resources\Ecr\TypesenseRepository;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TypesenseLogGroup;

/**
 * The single task-definition family every Typesense node runs
 * (yolo-{env}-typesense): its desired payload, the registered revision, and
 * whether the two still agree. Shared by the task-definition step (which
 * registers a drifted revision) and the nodes step (which has to know on the
 * plan pass that a revision is about to register — the plan runs before the
 * registration, so the live latest alone reads every node as current).
 */
final class TypesenseTaskDefinition
{
    public static function family(): string
    {
        return (new TypesenseService(0))->taskDefinitionFamily();
    }

    /**
     * The family's latest registered revision, or null before the first.
     *
     * @return array<string, mixed>|null
     */
    public static function live(): ?array
    {
        try {
            return Ecs::taskDefinition(self::family());
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * Whether the next sync registers a new revision: nothing registered yet,
     * the registered revision drifted from the desired payload, or the payload
     * can't render yet — its inputs (execution role, image tag) land earlier in
     * the same sync, so an unrenderable payload is a pending one.
     */
    public static function registrationPending(): bool
    {
        try {
            $desired = self::desired();
        } catch (ResourceDoesNotExistException) {
            return true;
        }

        $live = self::live();

        return $live === null || ! self::matches($desired, $live);
    }

    /**
     * Subset comparison — AWS enriches registered revisions with derived
     * fields we don't manage, and tags live outside the revision (see
     * SyncTaskDefinitionStep::matchesDesired).
     *
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>  $live
     */
    public static function matches(array $desired, array $live): bool
    {
        return self::subsetMatches(Arr::except($desired, ['tags']), $live);
    }

    /**
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>  $live
     */
    private static function subsetMatches(array $desired, array $live): bool
    {
        foreach ($desired as $key => $value) {
            if (! array_key_exists($key, $live)) {
                return false;
            }

            if (is_array($value)) {
                if (! is_array($live[$key]) || ! self::subsetMatches($value, $live[$key])) {
                    return false;
                }
            } elseif ((string) $value !== (string) $live[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
     * The desired revision. Throws while its inputs aren't resolvable — on a
     * greenfield plan the admin key, image and execution role may not exist
     * yet; on apply the earlier steps have provisioned them.
     *
     * @return array<string, mixed>
     */
    public static function desired(): array
    {
        $tag = Typesense::imageTag();

        if ($tag === null) {
            throw new ResourceDoesNotExistException('Typesense image tag is not resolvable yet');
        }

        $family = self::family();

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
}
