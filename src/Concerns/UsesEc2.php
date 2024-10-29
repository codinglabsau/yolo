<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\SecurityGroups;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesEc2
{
    protected static array $vpc;
    protected static array $internetGateway;
    protected static array $launchTemplate;
    protected static array $loadBalancer;
    protected static array $targetGroup;
    protected static array $subnets;
    protected static array $routeTable;
    protected static array $securityGroups;
    protected static array $loadBalancerSecurityGroup;
    protected static array $ec2SecurityGroup;
    protected static array $rdsSecurityGroup;

    protected static function ec2ByName(string $name, array $states = ['running'], bool $firstOnly = true, $throws = true): ?array
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

    public static function loadBalancer(): array
    {
        if (isset(static::$loadBalancer)) {
            return static::$loadBalancer;
        }

        $loadBalancers = Aws::elasticLoadBalancingV2()->describeLoadBalancers();

        foreach ($loadBalancers['LoadBalancers'] as $loadBalancer) {
            if ($loadBalancer['LoadBalancerName'] === Manifest::get('aws.alb')) {
                static::$loadBalancer = $loadBalancer;
                return $loadBalancer;
            }
        }

        throw new ResourceDoesNotExistException("Could not find load balancer");
    }

    public static function securityGroups($refresh = false): array
    {
        if (! $refresh && isset(static::$securityGroups)) {
            return static::$securityGroups;
        }

        $securityGroups = Aws::ec2()->describeSecurityGroups()['SecurityGroups'];

        static::$securityGroups = $securityGroups;

        return static::$securityGroups;
    }

    /**
     * @throws ResourceDoesNotExistException
     */
    public static function loadBalancerSecurityGroup(): array
    {
        if (isset(static::$loadBalancerSecurityGroup)) {
            return static::$loadBalancerSecurityGroup;
        }

        static::$loadBalancerSecurityGroup = static::securityGroupByName(SecurityGroups::LOAD_BALANCER_SECURITY_GROUP);

        return static::$loadBalancerSecurityGroup;
    }

    /**
     * @throws ResourceDoesNotExistException
     */
    public static function ec2SecurityGroup(): array
    {
        if (isset(static::$ec2SecurityGroup)) {
            return static::$ec2SecurityGroup;
        }

        static::$ec2SecurityGroup = static::securityGroupByName(SecurityGroups::EC2_SECURITY_GROUP);

        return static::$ec2SecurityGroup;
    }

    /**
     * @throws ResourceDoesNotExistException
     */
    public static function rdsSecurityGroup(): array
    {
        if (isset(static::$rdsSecurityGroup)) {
            return static::$rdsSecurityGroup;
        }

        static::$rdsSecurityGroup = static::securityGroupByName(SecurityGroups::RDS_SECURITY_GROUP);

        return static::$rdsSecurityGroup;
    }

    public static function securityGroupByName(string|\BackedEnum $name): array
    {
        if ($name instanceof \BackedEnum) {
            $name = $name->value;
        }

        $name = Helpers::keyedResourceName($name, exclusive: false);

        foreach (static::securityGroups(refresh: true) as $securityGroup) {
            if ($securityGroup['GroupName'] === $name) {
                return $securityGroup;
            }
        }

        throw new ResourceDoesNotExistException("Could not find Security Group matching name $name");
    }

    public static function targetGroup(): array
    {
        if (isset(static::$targetGroup)) {
            return static::$targetGroup;
        }

        $targetGroups = Aws::elasticLoadBalancingV2()->describeTargetGroups([
            'LoadBalancerArn' => static::loadBalancer()['LoadBalancerArn'],
        ])['TargetGroups'];

        if (count($targetGroups) === 0) {
            throw new ResourceDoesNotExistException(sprintf("Could not find target group for ALB %s", static::loadBalancer()['LoadBalancerName']));
        }

        static::$targetGroup = $targetGroups[0];

        return static::$targetGroup;
    }

    public static function loadBalancerListenerOnPort(int $port): array
    {
        $listeners = Aws::elasticLoadBalancingV2()->describeListeners([
            'LoadBalancerArn' => static::loadBalancer()['LoadBalancerArn'],
        ]);

        foreach ($listeners['Listeners'] as $listener) {
            if ($listener['Port'] === $port) {
                return $listener;
            }
        }

        throw new ResourceDoesNotExistException("Could not find listener on port $port");
    }

    public static function listenerCertificate(string $listenerArn, string $certificateArn): array
    {
        $listenerCertificates = Aws::elasticLoadBalancingV2()->describeListenerCertificates([
            'ListenerArn' => $listenerArn
        ]);

        foreach ($listenerCertificates['Certificates'] as $listenerCertificate) {
            if ($listenerCertificate['CertificateArn'] === $certificateArn) {
                return $listenerCertificate;
            }
        }

        throw new ResourceDoesNotExistException("Could not find listener certificate on listener $listenerArn");
    }

    public static function launchTemplate($refresh = false): array
    {
        if (! $refresh && isset(static::$launchTemplate)) {
            return static::$launchTemplate;
        }

        $launchTemplates = Aws::ec2()->describeLaunchTemplates([
            'Filters' => [
                [
                    'Name' => 'launch-template-name',
                    'Values' => [Helpers::keyedResourceName()],
                ],
            ],
        ])['LaunchTemplates'];

        if (count($launchTemplates) === 0) {
            throw new ResourceDoesNotExistException(sprintf("Could not find launch template %s. Run 'yolo compute:sync %s' to fix.", Helpers::keyedResourceName(), Helpers::environment()));
        }

        static::$launchTemplate = $launchTemplates[0];

        return static::$launchTemplate;
    }

    public static function launchTemplatePayload(): array
    {
        return [
            'LaunchTemplateName' => Helpers::keyedResourceName(),
            'LaunchTemplateData' => [
                'IamInstanceProfile' => [
                    'Name' => Manifest::get('aws.ec2.instance-profile'),
                ],
                'InstanceType' => Manifest::get('aws.ec2.instance-type'),
                'KeyName' => Manifest::name(),
                'SecurityGroupIds' => [
                    Manifest::get('aws.security-group-id'),
                ],
                'Monitoring' => [
                    'Enabled' => true,
                ],
            ],
        ];
    }

    public static function vpc(): array
    {
        if (isset(static::$vpc)) {
            return static::$vpc;
        }

        $name = Helpers::keyedResourceName(exclusive: false);

        $vpcs = Aws::ec2()->describeVpcs([
            'Filters' => [
                [
                    'Name' => 'tag:Name',
                    'Values' => [$name],
                ],
            ]
        ])
        ['Vpcs'];

        if (count($vpcs) === 0) {
            throw new ResourceDoesNotExistException(sprintf("Could not find VPC %s", $name));
        }

        static::$vpc = $vpcs[0];

        return static::$vpc;
    }

    public static function internetGateway(): array
    {
        if (isset(static::$internetGateway)) {
            return static::$internetGateway;
        }

        $name = Helpers::keyedResourceName(exclusive: false);

        $internetGateways = Aws::ec2()->describeInternetGateways([
            'Filters' => [
                [
                    'Name' => 'tag:Name',
                    'Values' => [$name],
                ],
            ]
        ])
        ['InternetGateways'];

        if (count($internetGateways) === 0) {
            throw new ResourceDoesNotExistException(sprintf("Could not find Internet Gateway %s", $name));
        }

        static::$internetGateway = $internetGateways[0];

        return static::$internetGateway;
    }

    public static function routeTable(): array
    {
        if (isset(static::$routeTable)) {
            return static::$routeTable;
        }

        $name = Helpers::keyedResourceName(exclusive: false);

        $routeTables = Aws::ec2()->describeRouteTables([
            'Filters' => [
                [
                    'Name' => 'tag:Name',
                    'Values' => [$name],
                ],
            ]
        ])
        ['RouteTables'];

        if (count($routeTables) === 0) {
            throw new ResourceDoesNotExistException(sprintf("Could not find Route Table %s", $name));
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
            throw new ResourceDoesNotExistException(sprintf("Could not find subnets for VPC %s", AwsResources::vpc()['VpcId']));
        }

        static::$subnets = $subnets;

        return static::$subnets;
    }

    public static function subnetByName(string $name): array
    {
        $fullSubnetName = Helpers::keyedResourceName($name, exclusive: false);

        foreach (static::subnets() as $subnet) {
            foreach ($subnet['Tags'] as $tag) {
                if ($tag['Key'] === 'Name' && $tag['Value'] === $fullSubnetName) {
                    return $subnet;
                }
            }
        }

        throw new ResourceDoesNotExistException("Could not find subnet matching name $fullSubnetName");
    }

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
}
