<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Audit;

use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Manifest;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Read-only health snapshot of the database the manifest `database:` key
 * declares. It is not a YOLO-tagged resource, so the tag-based inventory never
 * sees it — the health check looks it up by the manifest identifier. Audit-only,
 * never sync drift: an externally-hosted database must not block deploys.
 *
 * An unreadable database (missing, or the tier was denied) degrades to
 * `readable: false` and renders as a warning, never an error: we can't assert
 * protection is off, only that we couldn't confirm it's on.
 */
final readonly class RdsInspection
{
    /**
     * @param  array<int, array{identifier: string, role: string, class: string|null, promotionTier: int|null}>  $members
     * @param  array<int, string>  $securityGroupIds
     */
    private function __construct(
        public bool $readable,
        public ?string $reason,
        public string $identifier,
        public ?bool $cluster,
        public ?bool $deletionProtection,
        public ?string $engine,
        public ?string $engineVersion,
        public ?string $status,
        public ?string $instanceClass,
        public ?int $allocatedStorage,
        public ?bool $multiAz,
        public array $members,
        public ?string $subnetGroupName = null,
        public ?string $vpcId = null,
        public array $securityGroupIds = [],
        public ?bool $publiclyAccessible = null,
        public ?int $port = null,
    ) {}

    public static function inspect(): ?self
    {
        if (($database = Manifest::database()) === null) {
            return null;
        }

        // A failed classification degrades to unreadable like any failed read;
        // the kind is simply unknown at that point.
        try {
            $target = Rds::target();
        } catch (RdsException $exception) {
            return self::unreadable($database, null, self::reason($exception));
        } catch (ResourceDoesNotExistException) {
            return self::unreadable($database, null, 'no matching database in this account/region');
        }

        return $target['cluster']
            ? self::inspectCluster($target['identifier'])
            : self::inspectInstance($target['identifier']);
    }

    /**
     * An unreadable snapshot is never "protected" — but it's a warning, not the
     * error an explicit `false` is.
     */
    public function deletionProtectionEnabled(): bool
    {
        return $this->readable && $this->deletionProtection === true;
    }

    public function kind(): string
    {
        return match ($this->cluster) {
            // unreadable at classification — never learned its kind, don't guess
            null => 'database',
            true => 'Aurora cluster',
            false => 'instance',
        };
    }

    /**
     * @return array<string, string>
     */
    public function basics(): array
    {
        $rows = array_filter([
            'Engine' => $this->engineLabel(),
            'Status' => $this->status,
        ]);

        if ($this->cluster) {
            $rows['Members'] = sprintf('%d (%d writer, %d reader)', count($this->members), $this->writerCount(), $this->readerCount());

            return $rows;
        }

        return array_filter([
            ...$rows,
            'Class' => $this->instanceClass,
            'Storage' => $this->allocatedStorage === null ? null : sprintf('%d GiB', $this->allocatedStorage),
            'Multi-AZ' => $this->multiAz === null ? null : ($this->multiAz ? 'yes' : 'no'),
        ]);
    }

    protected static function inspectInstance(string $identifier): self
    {
        try {
            $instance = Rds::instance($identifier);
        } catch (RdsException $exception) {
            return self::unreadable($identifier, false, self::reason($exception));
        }

        if ($instance === null) {
            return self::unreadable($identifier, false, 'no matching DB instance');
        }

        return new self(
            readable: true,
            reason: null,
            identifier: $identifier,
            cluster: false,
            deletionProtection: (bool) ($instance['DeletionProtection'] ?? false),
            engine: $instance['Engine'] ?? null,
            engineVersion: $instance['EngineVersion'] ?? null,
            status: $instance['DBInstanceStatus'] ?? null,
            instanceClass: $instance['DBInstanceClass'] ?? null,
            allocatedStorage: isset($instance['AllocatedStorage']) ? (int) $instance['AllocatedStorage'] : null,
            multiAz: isset($instance['MultiAZ']) ? (bool) $instance['MultiAZ'] : null,
            members: [],
            subnetGroupName: $instance['DBSubnetGroup']['DBSubnetGroupName'] ?? null,
            vpcId: $instance['DBSubnetGroup']['VpcId'] ?? null,
            securityGroupIds: self::securityGroupIds($instance),
            publiclyAccessible: isset($instance['PubliclyAccessible']) ? (bool) $instance['PubliclyAccessible'] : null,
            port: Rds::portFromRecord($instance, cluster: false),
        );
    }

    protected static function inspectCluster(string $identifier): self
    {
        try {
            $cluster = Rds::cluster($identifier);
        } catch (RdsException $exception) {
            return self::unreadable($identifier, true, self::reason($exception));
        }

        if ($cluster === null) {
            return self::unreadable($identifier, true, 'no matching DB cluster');
        }

        // Best-effort: an access gap on the instance describe omits member detail,
        // never fails the cluster read.
        try {
            $instances = Rds::clusterInstances($identifier);
        } catch (RdsException) {
            $instances = [];
        }

        $classes = [];

        foreach ($instances as $instance) {
            $classes[$instance['DBInstanceIdentifier']] = $instance['DBInstanceClass'] ?? '—';
        }

        // The cluster describe carries no VPC or public accessibility — those are
        // per-member facts; any publicly accessible member exposes the cluster.
        $publiclyAccessible = collect($instances)
            ->filter(fn (array $instance): bool => array_key_exists('PubliclyAccessible', $instance))
            ->map(fn (array $instance): bool => (bool) $instance['PubliclyAccessible']);

        return new self(
            readable: true,
            reason: null,
            identifier: $identifier,
            cluster: true,
            deletionProtection: (bool) ($cluster['DeletionProtection'] ?? false),
            engine: $cluster['Engine'] ?? null,
            engineVersion: $cluster['EngineVersion'] ?? null,
            status: $cluster['Status'] ?? null,
            instanceClass: null,
            allocatedStorage: null,
            multiAz: null,
            members: self::members($cluster['DBClusterMembers'] ?? [], $classes),
            subnetGroupName: $cluster['DBSubnetGroup'] ?? null,
            vpcId: $instances[0]['DBSubnetGroup']['VpcId'] ?? null,
            securityGroupIds: self::securityGroupIds($cluster),
            publiclyAccessible: $publiclyAccessible->isEmpty() ? null : $publiclyAccessible->contains(true),
            port: Rds::portFromRecord($cluster, cluster: true),
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    protected static function securityGroupIds(array $record): array
    {
        return collect($record['VpcSecurityGroups'] ?? [])
            ->pluck('VpcSecurityGroupId')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawMembers
     * @param  array<string, string>  $classes
     * @return array<int, array{identifier: string, role: string, class: string|null, promotionTier: int|null}>
     */
    protected static function members(array $rawMembers, array $classes): array
    {
        $members = array_map(static fn (array $member): array => [
            'identifier' => $member['DBInstanceIdentifier'] ?? '—',
            'role' => ($member['IsClusterWriter'] ?? false) ? 'writer' : 'reader',
            'class' => $classes[$member['DBInstanceIdentifier'] ?? ''] ?? null,
            'promotionTier' => isset($member['PromotionTier']) ? (int) $member['PromotionTier'] : null,
        ], $rawMembers);

        usort($members, static fn (array $a, array $b): int => [$a['role'] === 'reader', $a['identifier']] <=> [$b['role'] === 'reader', $b['identifier']]);

        return $members;
    }

    protected static function unreadable(string $identifier, ?bool $cluster, string $reason): self
    {
        return new self(
            readable: false,
            reason: $reason,
            identifier: $identifier,
            cluster: $cluster,
            deletionProtection: null,
            engine: null,
            engineVersion: null,
            status: null,
            instanceClass: null,
            allocatedStorage: null,
            multiAz: null,
            members: [],
        );
    }

    protected static function reason(RdsException $exception): string
    {
        return match (true) {
            in_array($exception->getAwsErrorCode(), ['DBInstanceNotFound', 'DBClusterNotFound'], true) => 'no matching database in this account/region',
            $exception->getStatusCode() === 403, $exception->getAwsErrorCode() === 'AccessDenied' => 'access denied reading the database',
            default => $exception->getAwsErrorCode() ?? 'unknown error',
        };
    }

    protected function engineLabel(): ?string
    {
        if ($this->engine === null) {
            return null;
        }

        return $this->engineVersion === null
            ? $this->engine
            : sprintf('%s %s', $this->engine, $this->engineVersion);
    }

    protected function writerCount(): int
    {
        return count(array_filter($this->members, static fn (array $member): bool => $member['role'] === 'writer'));
    }

    protected function readerCount(): int
    {
        return count($this->members) - $this->writerCount();
    }
}
