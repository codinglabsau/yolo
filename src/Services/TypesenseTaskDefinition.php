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
 * Shared by the task-definition step and the nodes step: the plan pass runs
 * before the registration, so the live latest alone would read every node as
 * current — the nodes step needs to know a revision is about to register.
 */
final class TypesenseTaskDefinition
{
    public static function family(): string
    {
        return (new TypesenseService(0))->taskDefinitionFamily();
    }

    /**
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

    /** An unrenderable payload counts as pending — its inputs (execution role, image tag) land earlier in the same sync. */
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
     * Subset comparison — AWS enriches registered revisions with derived fields
     * we don't manage, and tags live outside the revision.
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
     * Throws while its inputs aren't resolvable (a greenfield plan pass).
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
            // No task role — Typesense calls no AWS APIs at runtime.
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
                    // Many concurrent connections + memory-mapped index files; Fargate's default nofile (1024) is too low.
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
