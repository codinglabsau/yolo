<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Ec2
{
    public static function vpc(string $name): array
    {
        return static::firstByNameTag('describeVpcs', 'Vpcs', $name, "Could not find VPC $name");
    }

    public static function subnet(string $name): array
    {
        return static::firstByNameTag('describeSubnets', 'Subnets', $name, "Could not find subnet $name");
    }

    public static function internetGateway(string $name): array
    {
        return static::firstByNameTag('describeInternetGateways', 'InternetGateways', $name, "Could not find internet gateway $name");
    }

    public static function routeTable(string $name): array
    {
        return static::firstByNameTag('describeRouteTables', 'RouteTables', $name, "Could not find route table $name");
    }

    /**
     * Availability zones for a region, in AWS's returned order. Subnets are
     * placed one-per-zone by index.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function availabilityZones(string $region): array
    {
        $availabilityZones = Aws::ec2()->describeAvailabilityZones([
            'Filters' => [['Name' => 'region-name', 'Values' => [$region]]],
        ])['AvailabilityZones'];

        if (count($availabilityZones) === 0) {
            throw new ResourceDoesNotExistException("Could not find availability zones for region $region");
        }

        return $availabilityZones;
    }

    /**
     * Every IPv4 CIDR block in use by a VPC in this region — across all
     * associations, YOLO-owned or not (the default VPC, a co-located Vapor
     * stack, anything) — so a new environment's VPC can be placed in a /16 that
     * overlaps nothing already on the account. Returns [] on a fresh account.
     *
     * @return array<int, string>
     */
    public static function cidrBlocksInUse(): array
    {
        return collect(Aws::ec2()->describeVpcs()['Vpcs'] ?? [])
            ->flatMap(fn (array $vpc): array => [
                $vpc['CidrBlock'] ?? null,
                ...collect($vpc['CidrBlockAssociationSet'] ?? [])->pluck('CidrBlock')->all(),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every subnet in a VPC (used to build the RDS DB subnet group).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function vpcSubnets(string $vpcId): array
    {
        $subnets = Aws::ec2()->describeSubnets([
            'Filters' => [['Name' => 'vpc-id', 'Values' => [$vpcId]]],
        ])['Subnets'];

        if (count($subnets) === 0) {
            throw new ResourceDoesNotExistException("Could not find subnets for VPC $vpcId");
        }

        return $subnets;
    }

    /**
     * The two DNS attributes a Route 53 private hosted zone needs set to true
     * to resolve inside a VPC — and therefore what Cloud Map private DNS (the
     * backing of ECS service discovery, e.g. Typesense's Raft peer addresses)
     * needs too. `describeVpcAttribute` returns a single attribute per call;
     * the keys match the `modifyVpcAttribute` parameter names so a drifted key
     * can be fed straight back in.
     *
     * @return array{EnableDnsSupport: bool, EnableDnsHostnames: bool}
     */
    public static function vpcDnsAttributes(string $vpcId): array
    {
        return [
            'EnableDnsSupport' => (bool) Aws::ec2()->describeVpcAttribute([
                'VpcId' => $vpcId,
                'Attribute' => 'enableDnsSupport',
            ])['EnableDnsSupport']['Value'],
            'EnableDnsHostnames' => (bool) Aws::ec2()->describeVpcAttribute([
                'VpcId' => $vpcId,
                'Attribute' => 'enableDnsHostnames',
            ])['EnableDnsHostnames']['Value'],
        ];
    }

    /**
     * The statuses in which a peering connection is "live" for YOLO's purposes.
     * Deleted/failed/expired connections linger in describe results for hours,
     * so every lookup must filter to these or a torn-down connection reads as
     * still existing.
     */
    public const array LIVE_PEERING_STATUSES = ['initiating-request', 'pending-acceptance', 'provisioning', 'active'];

    /**
     * A live VPC peering connection by its `Name` tag, or null when none is in
     * a live status (a deleted connection lingering in the describe is absence,
     * not presence).
     *
     * @return array<string, mixed>|null
     */
    public static function livePeeringConnection(string $name): ?array
    {
        return Aws::ec2()->describeVpcPeeringConnections([
            'Filters' => [
                ['Name' => 'tag:Name', 'Values' => [$name]],
                ['Name' => 'status-code', 'Values' => self::LIVE_PEERING_STATUSES],
            ],
        ])['VpcPeeringConnections'][0] ?? null;
    }

    /**
     * Every live YOLO-owned peering connection in this environment's tag
     * namespace — the set sync reconciles the declared `peering` list against.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function livePeeringConnections(string $environment): array
    {
        return Aws::ec2()->describeVpcPeeringConnections([
            'Filters' => [
                ['Name' => 'tag:yolo:environment', 'Values' => [$environment]],
                ['Name' => 'status-code', 'Values' => self::LIVE_PEERING_STATUSES],
            ],
        ])['VpcPeeringConnections'] ?? [];
    }

    /**
     * Whether an ACTIVE peering connection joins the two VPCs, either
     * orientation, YOLO-owned or not — a security group in one VPC can only
     * reference a group in the other once this is true.
     */
    public static function activePeeringBetween(string $vpcId, string $otherVpcId): bool
    {
        return collect(Aws::ec2()->describeVpcPeeringConnections([
            'Filters' => [['Name' => 'status-code', 'Values' => ['active']]],
        ])['VpcPeeringConnections'] ?? [])->contains(function (array $connection) use ($vpcId, $otherVpcId): bool {
            $requesterVpcId = $connection['RequesterVpcInfo']['VpcId'] ?? null;
            $accepterVpcId = $connection['AccepterVpcInfo']['VpcId'] ?? null;

            return ($requesterVpcId === $vpcId && $accepterVpcId === $otherVpcId)
                || ($requesterVpcId === $otherVpcId && $accepterVpcId === $vpcId);
        });
    }

    /**
     * A VPC by its id, or null when it doesn't exist — a declared peer VPC is
     * operator input, so absence is reported as pending drift, never a crash.
     *
     * @return array<string, mixed>|null
     */
    public static function vpcById(string $vpcId): ?array
    {
        try {
            return Aws::ec2()->describeVpcs(['VpcIds' => [$vpcId]])['Vpcs'][0] ?? null;
        } catch (Ec2Exception $exception) {
            if (str_starts_with($exception->getAwsErrorCode() ?? '', 'InvalidVpcID')) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Every route table in a VPC, sorted by id so callers plan and reclaim
     * deterministically. Empty when the VPC has none (it's gone).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function vpcRouteTables(string $vpcId): array
    {
        $routeTables = Aws::ec2()->describeRouteTables([
            'Filters' => [['Name' => 'vpc-id', 'Values' => [$vpcId]]],
        ])['RouteTables'] ?? [];

        usort($routeTables, fn (array $first, array $second): int => strcmp((string) $first['RouteTableId'], (string) $second['RouteTableId']));

        return $routeTables;
    }

    /**
     * The route tables that actually govern traffic in a VPC — those with at
     * least one subnet association. A route written anywhere else steers
     * nothing: a VPC built by another tool routinely leaves its main table
     * with zero subnet associations, so the main table is only the fallback
     * when NO table in the VPC has any (then every subnet uses it
     * implicitly). Sorted by id (see vpcRouteTables).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function subnetAssociatedRouteTables(string $vpcId): array
    {
        $routeTables = static::vpcRouteTables($vpcId);

        $subnetAssociated = array_values(array_filter(
            $routeTables,
            fn (array $routeTable): bool => collect($routeTable['Associations'] ?? [])
                ->contains(fn (array $association): bool => isset($association['SubnetId'])),
        ));

        if ($subnetAssociated !== []) {
            return $subnetAssociated;
        }

        return array_values(array_filter(
            $routeTables,
            fn (array $routeTable): bool => collect($routeTable['Associations'] ?? [])
                ->contains(fn (array $association): bool => (bool) ($association['Main'] ?? false)),
        ));
    }

    public static function securityGroup(string $name): array
    {
        $securityGroups = Aws::ec2()->describeSecurityGroups()['SecurityGroups'];

        foreach ($securityGroups as $securityGroup) {
            if ($securityGroup['GroupName'] === $name) {
                return $securityGroup;
            }
        }

        throw new ResourceDoesNotExistException("Could not find security group $name");
    }

    /**
     * Describe a single EC2 resource by its `Name` tag. The describe* calls all
     * share the `Filters` + `{ResourceType}` envelope shape, so one helper covers
     * VPCs, subnets, internet gateways and route tables.
     *
     * @return array<string, mixed>
     */
    protected static function firstByNameTag(string $operation, string $key, string $name, string $message): array
    {
        $results = Aws::ec2()->{$operation}([
            'Filters' => [['Name' => 'tag:Name', 'Values' => [$name]]],
        ])[$key];

        if (count($results) === 0) {
            throw new ResourceDoesNotExistException($message);
        }

        return $results[0];
    }

    /**
     * Lists ingress/egress rules for a security group, optionally filtered by a
     * `yolo:rule-type` tag value so callers can find their specific rule among
     * any custom ones.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function securityGroupRules(string $groupId, ?string $ruleType = null): array
    {
        $filters = [['Name' => 'group-id', 'Values' => [$groupId]]];

        if ($ruleType !== null) {
            $filters[] = ['Name' => 'tag:yolo:rule-type', 'Values' => [$ruleType]];
        }

        return Aws::ec2()->describeSecurityGroupRules([
            'Filters' => $filters,
        ])['SecurityGroupRules'];
    }

    /**
     * Delete a security group, retrying while AWS still reports a dependent
     * object. A security group can't be deleted while anything references it —
     * most often a still-detaching ENI from a just-stopped Fargate task (ENI
     * cleanup lags task stop by a minute or two over the graceful-drain window),
     * or a sibling SG's ingress rule mid-revoke. Everything that references the
     * group is torn down ahead of this, so the dependency is transient — retry
     * against AWS's own check until it clears, rather than asserting it's already
     * gone. A group already removed (InvalidGroup.NotFound) is the goal state and
     * returns cleanly. Any other error (or exhausting the attempts) propagates.
     */
    public static function deleteSecurityGroupWhenDetached(string $groupId, int $maxAttempts = 24, int $sleepSeconds = 10): void
    {
        $attempt = 0;

        while (true) {
            try {
                Aws::ec2()->deleteSecurityGroup(['GroupId' => $groupId]);

                return;
            } catch (Ec2Exception $exception) {
                if ($exception->getAwsErrorCode() === 'InvalidGroup.NotFound') {
                    return;
                }

                $attempt++;

                if ($attempt >= $maxAttempts || $exception->getAwsErrorCode() !== 'DependencyViolation') {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }
}
