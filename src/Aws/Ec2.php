<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
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
}
