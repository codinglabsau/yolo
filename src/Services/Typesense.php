<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\EnvManifest;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * Typesense — the environment's self-hosted search service. One three-node
 * Raft cluster per environment, shared by every consuming app: writes commit
 * on 2-of-3, a replaced node catches up from the surviving majority over the
 * network, so plain ephemeral Fargate storage is durable by replication
 * rather than by disk. Node count is hardcoded at three (Raft wants odd
 * quorums); the env-manifest entry declares version and per-node sizing.
 *
 * This definition is also the service's knowledge centre: the manifest entry's shape,
 * the admin key (seed-generated into the env-shared .env), the content-tagged
 * image, and the stable Cloud Map node addresses all resolve from here.
 */
class Typesense extends ServiceDefinition
{
    /** Raft wants odd quorums; three is the smallest that survives a node loss. */
    public const int NODES = 3;

    public const int API_PORT = 8108;

    public const int PEERING_PORT = 8107;

    /** The env-shared .env key holding the cluster's admin API key. */
    public const string ADMIN_KEY_NAME = 'TYPESENSE_API_KEY';

    /** @var string|null|false memoised admin key — false = not yet read */
    protected static string|null|false $adminKey = false;

    public function service(): Service
    {
        return Service::TYPESENSE;
    }

    public function envBacked(): bool
    {
        return true;
    }

    /**
     * Consuming typesense is env injection only — the app talks to the
     * cluster over HTTP with a scoped key, never to AWS — so there is no
     * runtime IAM to grant.
     */
    public function taskRoleStatements(): array
    {
        return [];
    }

    #[\Override]
    public function offerKeys(): array
    {
        return ['version', 'cpu', 'memory'];
    }

    /**
     * The manifest entry is the catalogue AND the cluster's shape — all
     * three keys are required so an environment can never run an implicit
     * version or sizing. Version bumps and resizes are manifest edits + a sync.
     */
    #[\Override]
    public function validateOffer(mixed $offer, string $filename): void
    {
        parent::validateOffer($offer, $filename);

        if (! is_array($offer)) {
            $offer = [];
        }

        $version = $offer['version'] ?? null;

        if (! is_string($version) || trim($version) === '') {
            throw new IntegrityCheckException(sprintf(
                'services.typesense in %s must declare a version (the typesense/typesense image tag, e.g. "29.0").',
                $filename,
            ));
        }

        foreach (['cpu', 'memory'] as $key) {
            if (! is_int($offer[$key] ?? null) || $offer[$key] <= 0) {
                throw new IntegrityCheckException(sprintf(
                    'services.typesense.%s in %s must be a positive integer (Fargate %s units, e.g. %s).',
                    $key,
                    $filename,
                    $key,
                    $key === 'cpu' ? '256' : '1024',
                ));
            }
        }
    }

    #[\Override]
    public function environmentSteps(): array
    {
        return [
            // Secrets + image first: the admin key seeds the env-shared .env,
            // the content-tagged image bakes it in — both inputs to the task
            // definition further down.
            Steps\Sync\Environment\SyncTypesenseAdminKeyStep::class,
            Steps\Sync\Environment\SyncTypesenseEcrRepositoryStep::class,
            Steps\Sync\Environment\BuildTypesenseImageStep::class,
            // The env services cluster hosts every env-shared service task;
            // typesense is its first occupant, so its lifecycle drives it for
            // now (move to the base env tier when a second service lands).
            Steps\Sync\Environment\SyncServicesClusterStep::class,
            Steps\Sync\Environment\SyncTypesenseLogGroupStep::class,
            // Stable node addresses: a private Cloud Map namespace + one DNS
            // service per node, so Raft peers survive task replacement.
            Steps\Sync\Environment\SyncTypesenseNamespaceStep::class,
            Steps\Sync\Environment\SyncTypesenseDiscoveryServicesStep::class,
            Steps\Sync\Environment\SyncTypesenseSecurityGroupStep::class,
            Steps\Sync\Environment\SyncTypesenseTaskDefinitionStep::class,
            Steps\Sync\Environment\SyncTypesenseNodesStep::class,
        ];
    }

    /**
     * The declared image version — the typesense/typesense tag.
     */
    public static function version(): ?string
    {
        $version = EnvManifest::get('services.typesense.version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    public static function cpu(): int
    {
        return (int) EnvManifest::get('services.typesense.cpu', 256);
    }

    public static function memory(): int
    {
        return (int) EnvManifest::get('services.typesense.memory', 1024);
    }

    /**
     * The cluster's admin API key, read from the env-shared .env in the env
     * config bucket — null when not yet generated (SyncTypesenseAdminKeyStep
     * seeds it) or when the bucket/file doesn't exist yet (a greenfield plan
     * pass). Memoised so both sync passes see the same key.
     */
    public static function adminKey(): ?string
    {
        if (static::$adminKey !== false) {
            return static::$adminKey;
        }

        try {
            $body = (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => Paths::s3SharedEnvKey(),
            ])['Body'];
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return static::$adminKey = null;
            }

            throw $e;
        }

        $key = Dotenv::parse($body)[static::ADMIN_KEY_NAME] ?? null;

        return static::$adminKey = (is_string($key) && $key !== '' ? $key : null);
    }

    /**
     * The content tag the image build pushes: declared version + a key
     * fingerprint, so a version bump or a key rotation produces a new tag (and
     * a task-def revision that rolls the nodes) while an unchanged pair skips
     * the build entirely. Null until both inputs exist.
     */
    public static function imageTag(): ?string
    {
        $version = static::version();
        $key = static::adminKey();

        if ($version === null || $key === null) {
            return null;
        }

        return sprintf('%s-%s', $version, substr(hash('sha256', $key), 0, 12));
    }

    /**
     * The stable private DNS name of one node.
     */
    public static function nodeAddress(int $node): string
    {
        return sprintf('typesense-%d.%s', $node, static::namespaceName());
    }

    /**
     * Every node's peering entry (host:peering_port:api_port) — identical on
     * all three nodes; each identifies itself by matching a local interface.
     *
     * @return array<int, string>
     */
    public static function peers(): array
    {
        return array_map(
            fn (int $node): string => sprintf('%s:%d:%d', static::nodeAddress($node), static::PEERING_PORT, static::API_PORT),
            range(0, static::NODES - 1),
        );
    }

    /**
     * The environment's private service-discovery namespace.
     */
    public static function namespaceName(): string
    {
        return sprintf('%s.internal', Helpers::environment());
    }

    /**
     * Forget the memoised admin key — tests bind fresh S3 mocks per case.
     */
    public static function reset(): void
    {
        static::$adminKey = false;
    }
}
