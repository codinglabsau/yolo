<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Rds
{
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
     * The instance class for each member of an Aurora cluster, keyed by instance
     * identifier — so the audit can show the writer's and readers' sizes. A single
     * describe filtered to the cluster; read-only. Best-effort detail, so the probe
     * tolerates an empty result.
     *
     * @return array<string, string>
     */
    public static function clusterInstanceClasses(string $clusterIdentifier): array
    {
        $classes = [];
        $marker = null;

        do {
            $page = Aws::rds()->describeDBInstances(array_filter([
                'Filters' => [['Name' => 'db-cluster-id', 'Values' => [$clusterIdentifier]]],
                'Marker' => $marker,
            ]));

            foreach ($page['DBInstances'] ?? [] as $instance) {
                $classes[$instance['DBInstanceIdentifier']] = $instance['DBInstanceClass'] ?? '—';
            }

            $marker = $page['Marker'] ?? null;
        } while ($marker !== null);

        return $classes;
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
