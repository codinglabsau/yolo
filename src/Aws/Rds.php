<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Rds
{
    /** @var array<string, array{identifier: string, cluster: bool}> */
    protected static array $targets = [];

    /** @var array<string, int|null> */
    protected static array $ports = [];

    /**
     * Classified live (cluster if it describes as one, else instance). A declared
     * name matching neither throws — a manifest error to surface, not an empty
     * dashboard panel. Memoised: the kind is stable and every RBAC tier holds
     * `rds:Describe*`, so plan/apply and every tier resolve the same
     * classification — the dashboard body's tier-parity contract leans on this.
     *
     * @return array{identifier: string, cluster: bool}|null
     */
    public static function target(): ?array
    {
        $database = Manifest::database();

        if ($database === null) {
            return null;
        }

        return static::$targets[$database] ??= static::classify($database);
    }

    /**
     * The database itself is the source of truth for its port — a manifest key
     * or engine→port table could go stale. Null means "no port to authorise":
     * nothing declared, a describe that failed for a reason other than absence
     * (a fenced read tier or throttle must degrade, never fail a deploy gate), or
     * a record with no port yet. Callers never substitute a default — a guessed
     * port would put a rule on a shared, long-lived group that sync can never
     * revoke. A declared database that resolves to nothing still throws via
     * {@see self::target()}.
     */
    public static function port(): ?int
    {
        try {
            $target = static::target();
        } catch (RdsException) {
            return null;
        }

        if ($target === null) {
            return null;
        }

        return static::$ports[$target['identifier']] ??= static::resolvePort($target);
    }

    /**
     * An instance's endpoint is absent while it's still being created — hence
     * null, never an assumed default.
     *
     * @param  array<string, mixed>  $record
     */
    public static function portFromRecord(array $record, bool $cluster): ?int
    {
        $port = $cluster
            ? ($record['Port'] ?? null)
            : ($record['Endpoint']['Port'] ?? null);

        return is_numeric($port) ? (int) $port : null;
    }

    /** Drop memoised classifications and ports (test reset). */
    public static function flushTargets(): void
    {
        static::$targets = [];
        static::$ports = [];
    }

    /**
     * @param  array{identifier: string, cluster: bool}  $target
     */
    protected static function resolvePort(array $target): ?int
    {
        try {
            $record = $target['cluster']
                ? static::cluster($target['identifier'])
                : static::instance($target['identifier']);
        } catch (RdsException) {
            return null;
        }

        return $record === null ? null : static::portFromRecord($record, $target['cluster']);
    }

    /**
     * @return array{identifier: string, cluster: bool}
     */
    protected static function classify(string $identifier): array
    {
        try {
            if (static::cluster($identifier) !== null) {
                return ['identifier' => $identifier, 'cluster' => true];
            }
        } catch (RdsException $exception) {
            if (! static::isNotFound($exception)) {
                throw $exception;
            }
        }

        try {
            if (static::instance($identifier) !== null) {
                return ['identifier' => $identifier, 'cluster' => false];
            }
        } catch (RdsException $exception) {
            if (! static::isNotFound($exception)) {
                throw $exception;
            }
        }

        throw new ResourceDoesNotExistException(
            "The manifest `database:` declares \"$identifier\" but no RDS cluster or instance with that identifier exists in this account/region. "
            . 'Create the database first (into the `yolo-{env}-private-subnet-group` subnet group and `yolo-{env}-rds-security-group` security group `sync` provisions), then declare it — or drop the key until you have.'
        );
    }

    /**
     * RDS not-found codes come in bare and `Fault`-suffixed forms depending on
     * the operation (DBInstanceNotFound vs DBClusterNotFoundFault) — match both.
     */
    protected static function isNotFound(RdsException $exception): bool
    {
        return in_array($exception->getAwsErrorCode(), [
            'DBClusterNotFound', 'DBClusterNotFoundFault',
            'DBInstanceNotFound', 'DBInstanceNotFoundFault',
        ], true);
    }

    public static function subnetGroup(string $name): array
    {
        foreach (Aws::rds()->describeDBSubnetGroups()['DBSubnetGroups'] ?? [] as $subnetGroup) {
            if ($subnetGroup['DBSubnetGroupName'] === $name) {
                return $subnetGroup;
            }
        }

        throw new ResourceDoesNotExistException("Could not find RDS subnet group $name");
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function instance(string $identifier): ?array
    {
        return Aws::rds()->describeDBInstances([
            'DBInstanceIdentifier' => $identifier,
        ])['DBInstances'][0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function cluster(string $identifier): ?array
    {
        return Aws::rds()->describeDBClusters([
            'DBClusterIdentifier' => $identifier,
        ])['DBClusters'][0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function clusterInstances(string $clusterIdentifier): array
    {
        $instances = [];
        $marker = null;

        do {
            $page = Aws::rds()->describeDBInstances(array_filter([
                'Filters' => [['Name' => 'db-cluster-id', 'Values' => [$clusterIdentifier]]],
                'Marker' => $marker,
            ]));

            $instances = [...$instances, ...($page['DBInstances'] ?? [])];

            $marker = $page['Marker'] ?? null;
        } while ($marker !== null);

        return $instances;
    }

    /**
     * @return array<string, string>
     */
    public static function instanceEndpoints(): array
    {
        $endpoints = [];
        $marker = null;

        do {
            $page = Aws::rds()->describeDBInstances(array_filter(['Marker' => $marker]));

            foreach ($page['DBInstances'] ?? [] as $instance) {
                if (($address = $instance['Endpoint']['Address'] ?? null) !== null) {
                    $endpoints[(string) $instance['DBInstanceIdentifier']] = (string) $address;
                }
            }

            $marker = $page['Marker'] ?? null;
        } while ($marker !== null);

        return $endpoints;
    }

    /**
     * A network-shell teardown refuses while this isn't empty — YOLO never
     * deletes a database it doesn't own.
     *
     * @return array<int, string>
     */
    public static function instancesInVpc(string $vpcId): array
    {
        $identifiers = [];
        $marker = null;

        do {
            $page = Aws::rds()->describeDBInstances(array_filter(['Marker' => $marker]));

            foreach ($page['DBInstances'] ?? [] as $instance) {
                if (($instance['DBSubnetGroup']['VpcId'] ?? null) === $vpcId) {
                    $identifiers[] = $instance['DBInstanceIdentifier'];
                }
            }

            $marker = $page['Marker'] ?? null;
        } while ($marker !== null);

        return $identifiers;
    }
}
