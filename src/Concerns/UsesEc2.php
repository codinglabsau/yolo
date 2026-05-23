<?php

namespace Codinglabs\Yolo\Concerns;

use BackedEnum;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\PublicSubnets;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesEc2
{
    protected static array $vpc;

    protected static array $internetGateway;

    protected static array $subnets;

    protected static array $routeTable;

    protected static array $loadBalancerSecurityGroup;

    protected static array $ecsTaskSecurityGroup;

    protected static array $rdsSecurityGroup;

    public static function availabilityZones(string $region): array
    {
        $availabilityZones = Aws::ec2()->describeAvailabilityZones([
            'Filters' => [
                [
                    'Name' => 'region-name',
                    'Values' => [$region],
                ],
            ],
        ])['AvailabilityZones'];

        if (count($availabilityZones) === 0) {
            throw new ResourceDoesNotExistException("Could not find availability zones for region $region");
        }

        return $availabilityZones;
    }

    public static function ec2ByName(string $name, array $states = ['running'], bool $firstOnly = true, $throws = true): ?array
    {
        $instances = collect(Aws::ec2()->describeInstances([
            'Filters' => [
                ['Name' => 'tag:Name', 'Values' => [$name]],
            ],
        ])['Reservations'])
            ->flatMap(fn ($reservation) => $reservation['Instances'])
            ->filter(function ($instance) use ($states) {
                // if running state is required, ensure a public IP has been set
                if (in_array('running', $states) && $instance['State']['Name'] === 'running') {
                    return isset($instance['PublicIpAddress']);
                }

                // current state must be in $states
                return in_array($instance['State']['Name'], $states);
            })
            ->values();

        if (empty($instances)) {
            if ($throws) {
                throw new ResourceDoesNotExistException("Could not find EC2 instance name $name");
            }

            return null;
        }

        return $firstOnly
            ? $instances->first()
            : $instances->toArray();
    }

    public static function ec2IpByName(string $name, bool $firstOnly = true): string|array
    {
        if ($firstOnly) {
            return static::ec2ByName(name: $name)['PublicIpAddress'];
        }

        return collect(static::ec2ByName(name: $name, firstOnly: false))
            ->map(fn ($instance) => $instance['PublicIpAddress'])
            ->toArray();
    }

    public static function securityGroups(): array
    {
        // Intentionally un-memoised: steps frequently describe → catch-not-found → create → re-describe
        // within a single sync, and a stale cache silently breaks the re-read. The extra describe
        // cost is negligible and the bug class disappears. LPX-596 tracks the rest of the cache strip.
        return Aws::ec2()->describeSecurityGroups()['SecurityGroups'];
    }

    /**
     * @throws ResourceDoesNotExistException
     */
    public static function loadBalancerSecurityGroup(): array
    {
        if (isset(static::$loadBalancerSecurityGroup)) {
            return static::$loadBalancerSecurityGroup;
        }

        static::$loadBalancerSecurityGroup = static::securityGroupByName(SecurityGroup::LOAD_BALANCER_SECURITY_GROUP);

        return static::$loadBalancerSecurityGroup;
    }

    /**
     * @throws ResourceDoesNotExistException
     */
    public static function rdsSecurityGroup(): array
    {
        if (isset(static::$rdsSecurityGroup)) {
            return static::$rdsSecurityGroup;
        }

        static::$rdsSecurityGroup = static::securityGroupByName(Manifest::get('aws.rds.security-group', SecurityGroup::RDS_SECURITY_GROUP));

        return static::$rdsSecurityGroup;
    }

    /**
     * @throws ResourceDoesNotExistException
     */
    public static function ecsTaskSecurityGroup(): array
    {
        if (isset(static::$ecsTaskSecurityGroup)) {
            return static::$ecsTaskSecurityGroup;
        }

        $name = Manifest::get(
            'aws.ecs.security-group',
            Helpers::keyedResourceName(SecurityGroup::ECS_TASK_SECURITY_GROUP, exclusive: true)
        );

        static::$ecsTaskSecurityGroup = static::securityGroupByName($name);

        return static::$ecsTaskSecurityGroup;
    }

    public static function securityGroupByName(string|BackedEnum $name): array
    {
        if ($name instanceof BackedEnum) {
            $name = Helpers::keyedResourceName($name->value, exclusive: false);
        }

        foreach (static::securityGroups() as $securityGroup) {
            if ($securityGroup['GroupName'] === $name) {
                return $securityGroup;
            }
        }

        throw new ResourceDoesNotExistException("Could not find Security Group matching name $name");
    }

    public static function vpc(): array
    {
        if (isset(static::$vpc)) {
            return static::$vpc;
        }

        $name = Manifest::get('aws.vpc', Helpers::keyedResourceName(exclusive: false));

        $vpcs = Aws::ec2()->describeVpcs([
            'Filters' => [
                [
                    'Name' => 'tag:Name',
                    'Values' => [$name],
                ],
            ],
        ])['Vpcs'];

        if (count($vpcs) === 0) {
            throw new ResourceDoesNotExistException(sprintf('Could not find VPC %s', $name));
        }

        static::$vpc = $vpcs[0];

        return static::$vpc;
    }

    public static function internetGateway(): array
    {
        if (isset(static::$internetGateway)) {
            return static::$internetGateway;
        }

        $name = Manifest::get('aws.internet-gateway', Helpers::keyedResourceName(exclusive: false));

        $internetGateways = Aws::ec2()->describeInternetGateways([
            'Filters' => [
                [
                    'Name' => 'tag:Name',
                    'Values' => [$name],
                ],
            ],
        ])['InternetGateways'];

        if (count($internetGateways) === 0) {
            throw new ResourceDoesNotExistException(sprintf('Could not find Internet Gateway %s', $name));
        }

        static::$internetGateway = $internetGateways[0];

        return static::$internetGateway;
    }

    public static function routeTable(): array
    {
        if (isset(static::$routeTable)) {
            return static::$routeTable;
        }

        $name = Manifest::has('aws.route-table')
            ? Manifest::get('aws.route-table')
            : Helpers::keyedResourceName(exclusive: false);

        $routeTables = Aws::ec2()->describeRouteTables([
            'Filters' => [
                [
                    'Name' => 'tag:Name',
                    'Values' => [$name],
                ],
            ],
        ])['RouteTables'];

        if (count($routeTables) === 0) {
            throw new ResourceDoesNotExistException(sprintf('Could not find Route Table %s', $name));
        }

        static::$routeTable = $routeTables[0];

        return static::$routeTable;
    }

    public static function subnets(): array
    {
        if (isset(static::$subnets)) {
            return static::$subnets;
        }

        $subnets = Aws::ec2()->describeSubnets([
            'Filters' => [
                [
                    'Name' => 'vpc-id',
                    'Values' => [AwsResources::vpc()['VpcId']],
                ],
            ],
        ])['Subnets'];

        if (count($subnets) === 0) {
            throw new ResourceDoesNotExistException(sprintf('Could not find subnets for VPC %s', AwsResources::vpc()['VpcId']));
        }

        return $subnets;
    }

    public static function publicSubnetIds(): array
    {
        return collect(PublicSubnets::cases())
            ->map(fn (PublicSubnets $subnet) => static::subnetByName($subnet->value)['SubnetId'])
            ->all();
    }

    public static function subnetByName(string $name, $relative = true): array
    {
        $fullSubnetName = $relative
            ? Helpers::keyedResourceName($name, exclusive: false)
            : $name;

        foreach (static::subnets() as $subnet) {
            foreach ($subnet['Tags'] as $tag) {
                if ($tag['Key'] === 'Name' && $tag['Value'] === $fullSubnetName) {
                    return $subnet;
                }
            }
        }

        throw new ResourceDoesNotExistException("Could not find subnet matching name $fullSubnetName");
    }
}
