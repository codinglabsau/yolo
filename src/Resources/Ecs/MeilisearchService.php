<?php

namespace Codinglabs\Yolo\Resources\Ecs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Resources\Ssm\MeilisearchMasterKey;
use Codinglabs\Yolo\Resources\Ec2\MeilisearchSecurityGroup;
use Codinglabs\Yolo\Resources\ElbV2\MeilisearchTargetGroup;
use Codinglabs\Yolo\Resources\Iam\MeilisearchExecutionRole;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\CloudWatchLogs\MeilisearchLogGroup;

/**
 * The shared Meilisearch service: a Fargate singleton running the pinned
 * upstream image as-is (no custom Dockerfile — Meilisearch is a single static
 * binary). One instance per environment, shared by every app that declares
 * `scout.driver: meilisearch`; apps are isolated logically by Scout index
 * prefix, the direct analogue of the shared Valkey cache.
 *
 * Everything here is frozen at create — the version, sizing and task definition
 * are pinned YOLO-source constants read only when the service is first
 * provisioned, mirroring the CacheCluster precedent exactly. Apps deploy with
 * their own pinned YOLO versions, so an env-shared resource that reconciled
 * config would thrash between whatever versions happen to sync; tags are the
 * only thing sync touches. An engine upgrade is a deliberate out-of-band
 * operation (the index format is version-locked), never an implicit drift-fix.
 *
 * The index lives on the task's ephemeral storage (the Fargate default 20GB) —
 * deliberately. On Fargate an EBS volume is per-task and deleted when the task
 * stops, so it buys no persistence over ephemeral storage; and the index is a
 * rebuildable cache, not a source of truth — a replaced task means a Scout
 * reimport from the database, not data loss. Search must fail soft in the app
 * during that window.
 */
class MeilisearchService implements Resource
{
    use ResolvesTags;

    // Pinned: the on-disk LMDB index format is version-locked, so an upgrade is
    // a dump→restore (or dumpless-upgrade) operation, never an image bump that
    // a routine sync applies.
    public const VERSION = 'v1.46.1';

    public const IMAGE = 'getmeili/meilisearch';

    public const PORT = 7700;

    public const CONTAINER_NAME = 'meilisearch';

    // One size for every environment: RAM ≥ index keeps reads memory-mapped hot
    // (CL's largest index is ~100MB; LP's under 1GB), and indexing peaks at
    // ~2-3× index size. A manifest knob would let three apps declare three
    // sizes for the one shared instance, so there isn't one.
    public const CPU = '1024';

    public const MEMORY = '4096';

    public function name(): string
    {
        return $this->keyedName('meilisearch');
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            Ecs::service((new ServicesCluster())->name(), $this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ecs::service((new ServicesCluster())->name(), $this->name())['serviceArn'];
    }

    public function create(): void
    {
        Aws::ecs()->registerTaskDefinition($this->taskDefinitionPayload());
        Aws::ecs()->createService($this->createPayload());
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEcsTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * @return array<string, mixed>
     */
    public function taskDefinitionPayload(): array
    {
        return [
            'family' => $this->name(),
            'networkMode' => 'awsvpc',
            'requiresCompatibilities' => ['FARGATE'],
            'cpu' => self::CPU,
            'memory' => self::MEMORY,
            'executionRoleArn' => (new MeilisearchExecutionRole())->arn(),
            'containerDefinitions' => [
                [
                    'name' => self::CONTAINER_NAME,
                    'image' => self::IMAGE . ':' . self::VERSION,
                    'essential' => true,
                    'portMappings' => [
                        [
                            'containerPort' => self::PORT,
                            'hostPort' => self::PORT,
                            'protocol' => 'tcp',
                        ],
                    ],
                    'environment' => [
                        // Production mode requires the master key and disables
                        // the bundled search preview UI.
                        ['name' => 'MEILI_ENV', 'value' => 'production'],
                        ['name' => 'MEILI_HTTP_ADDR', 'value' => '0.0.0.0:' . self::PORT],
                        ['name' => 'MEILI_NO_ANALYTICS', 'value' => 'true'],
                    ],
                    'secrets' => [
                        [
                            'name' => 'MEILI_MASTER_KEY',
                            // A same-region SSM parameter can be referenced by
                            // name — no ARN lookup needed.
                            'valueFrom' => (new MeilisearchMasterKey())->name(),
                        ],
                    ],
                    'linuxParameters' => [
                        'initProcessEnabled' => true,
                    ],
                    'logConfiguration' => [
                        'logDriver' => 'awslogs',
                        'options' => [
                            'awslogs-group' => (new MeilisearchLogGroup())->name(),
                            'awslogs-region' => Manifest::get('region'),
                            'awslogs-stream-prefix' => self::CONTAINER_NAME,
                        ],
                    ],
                ],
            ],
            'tags' => Aws::ecsTags($this->tags()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createPayload(): array
    {
        return [
            'cluster' => (new ServicesCluster())->name(),
            'serviceName' => $this->name(),
            'taskDefinition' => $this->name(),
            // A singleton by storage design: each task has its own ephemeral
            // index, so two concurrent tasks would serve divergent results.
            // Stop-then-start (0/100) keeps it single — the replacement task
            // starts empty and reindexes, which is the accepted DR model.
            'desiredCount' => 1,
            'launchType' => 'FARGATE',
            'deploymentConfiguration' => [
                'deploymentCircuitBreaker' => [
                    'enable' => true,
                    'rollback' => true,
                ],
                'minimumHealthyPercent' => 0,
                'maximumPercent' => 100,
            ],
            'networkConfiguration' => [
                'awsvpcConfiguration' => [
                    'subnets' => PublicSubnet::ids(),
                    'securityGroups' => [(new MeilisearchSecurityGroup())->arn()],
                    'assignPublicIp' => 'ENABLED',
                ],
            ],
            'loadBalancers' => [
                [
                    'targetGroupArn' => (new MeilisearchTargetGroup())->arn(),
                    'containerName' => self::CONTAINER_NAME,
                    'containerPort' => self::PORT,
                ],
            ],
            'healthCheckGracePeriodSeconds' => 60,
            'tags' => Aws::ecsTags($this->tags()),
            'propagateTags' => 'SERVICE',
        ];
    }
}
