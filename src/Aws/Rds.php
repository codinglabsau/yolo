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
     * The RDS target the manifest `database:` key declares — the bare human name
     * of a plain instance or an Aurora cluster — classified live: a name that
     * describes as a cluster is a cluster, otherwise a plain instance. Null when
     * no database is declared; a name matching neither throws — a declared
     * database that doesn't exist is a manifest error to surface, not an empty
     * dashboard panel to puzzle over.
     *
     * Memoised per process: which kind a name is is a stable fact (a name can't
     * flip cluster↔instance run-to-run), and every RBAC tier holds the
     * `rds:Describe*` read (ObserverPolicy — inherited by the deployer's
     * AppObserverPolicy and attached to the admin role), so the plan and apply
     * passes and every tier resolve the same classification. The dashboard
     * body's tier-parity contract leans on this.
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
     * The port the declared database actually listens on — MySQL's 3306,
     * Postgres's 5432, or whatever non-default port it was created with. Every
     * ingress rule, revoke and tunnel derives from this, so a Postgres app
     * needs no configuration: the database itself is the source of truth, which
     * can't go stale the way a manifest key or an engine→port table could.
     *
     * Null means "no port to authorise", and there are three ways to get it: no
     * `database:` is declared (the app doesn't use one, or the database hasn't
     * been created yet — the caller writes no ingress rule); the describe failed
     * for a reason that isn't the database's absence (a fenced read tier, a
     * throttle), which must never masquerade as a manifest error so it degrades
     * rather than throwing — a permissions hiccup can't be allowed to fail a
     * deploy gate; or the record carries no port yet (an instance still being
     * created). No caller ever substitutes a default: a port YOLO guessed would
     * put a rule on a shared, long-lived group that sync can never revoke.
     *
     * A DECLARED database that resolves to nothing throws
     * ({@see self::target()}) — that's a manifest error, not a state to tolerate.
     * Guessing a port and writing a speculative rule for it would let a mistyped
     * identifier, or a manifest edited ahead of the database, look like a clean
     * sync.
     *
     * Memoised per identifier alongside the classification it rides on.
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
     * The port carried by a live instance or cluster record the caller already
     * holds — no second describe. A cluster reports its port at the top level;
     * an instance hangs it off the endpoint, which is absent while the instance
     * is still being created — hence null, never an assumed default.
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
     * The live record for a plain (non-Aurora) DB instance, or null when the
     * describe returns nothing. Read-only — surfaces deletion protection, the
     * instance class/size, engine and Multi-AZ to the audit health probe. An
     * unknown identifier throws RdsException (DBInstanceNotFound) straight through
     * for the caller to classify (the probe degrades it to a warning).
     *
     * @return array<string, mixed>|null
     */
    public static function instance(string $identifier): ?array
    {
        return Aws::rds()->describeDBInstances([
            'DBInstanceIdentifier' => $identifier,
        ])['DBInstances'][0] ?? null;
    }

    /**
     * The live record for an Aurora DB cluster, including its member list (writer
     * + readers via DBClusterMembers), or null when the describe returns nothing.
     * Read-only. An unknown identifier throws RdsException (DBClusterNotFound)
     * straight through for the caller to classify.
     *
     * @return array<string, mixed>|null
     */
    public static function cluster(string $identifier): ?array
    {
        return Aws::rds()->describeDBClusters([
            'DBClusterIdentifier' => $identifier,
        ])['DBClusters'][0] ?? null;
    }

    /**
     * The full instance record for each member of an Aurora cluster — the audit
     * derives the writer's and readers' sizes plus the network posture facts the
     * cluster describe doesn't carry (the subnet group's VPC, public
     * accessibility). A single describe filtered to the cluster; read-only.
     * Best-effort detail, so the probe tolerates an empty result.
     *
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
     * Every DB instance in the account that has an endpoint, as
     * identifier => endpoint address — the candidate list for the cutover
     * target picker. Instances still creating (no endpoint yet) are omitted;
     * read-only.
     *
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
     * The identifiers of every live DB instance whose subnet group sits in the
     * given VPC. A network-shell teardown refuses while this isn't empty — the
     * database lives in the VPC's private subnets and pins the whole network, and
     * YOLO never deletes a database it doesn't own.
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
