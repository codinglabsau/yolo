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
     * In AWS's returned order — subnets are placed one-per-zone by index.
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
     * Every CIDR across all associations, YOLO-owned or not, so a new env VPC's
     * /16 overlaps nothing already on the account. [] on a fresh account.
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
     * Both must be true for a private hosted zone (and so Cloud Map service
     * discovery) to resolve inside the VPC. `describeVpcAttribute` returns one
     * attribute per call; the keys match the `modifyVpcAttribute` parameter names
     * so a drifted key feeds straight back in.
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
     * Deleted/failed/expired connections linger in describe results for hours —
     * every lookup must filter to these or a torn-down connection reads as live.
     */
    public const array LIVE_PEERING_STATUSES = ['initiating-request', 'pending-acceptance', 'provisioning', 'active'];

    /**
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
     * Either orientation, YOLO-owned or not — a security group in one VPC can
     * only reference a group in the other once this is true.
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
     * Null when missing — a declared peer VPC is operator input, so absence is
     * pending drift, never a crash.
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
     * Sorted by id so callers plan and reclaim deterministically.
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
     * A route written to a table with no subnet association steers nothing, and
     * a VPC built by another tool routinely leaves its main table with none —
     * so the main table is only the fallback when NO table has any (every subnet
     * then uses it implicitly).
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

    public static function securityGroup(string $name, string $vpcId): array
    {
        // Group names are only unique per VPC — a name-only lookup can leak a
        // same-named group's id from another VPC into creates here, which AWS
        // rejects. The client-side re-check keeps mock-backed tests honest.
        $securityGroups = Aws::ec2()->describeSecurityGroups([
            'Filters' => [
                ['Name' => 'group-name', 'Values' => [$name]],
                ['Name' => 'vpc-id', 'Values' => [$vpcId]],
            ],
        ])['SecurityGroups'] ?? [];

        foreach ($securityGroups as $securityGroup) {
            if ($securityGroup['GroupName'] === $name) {
                return $securityGroup;
            }
        }

        throw new ResourceDoesNotExistException("Could not find security group $name in VPC $vpcId");
    }

    /**
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
     * DependencyViolation here is transient — everything referencing the group
     * is torn down ahead of this, but ENI detach lags a stopped Fargate task by a
     * minute or two — so retry against AWS's own check rather than asserting.
     * InvalidGroup.NotFound is the goal state.
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
