<?php

namespace Codinglabs\Yolo\Concerns;

use BackedEnum;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesEc2
{
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
        return Aws::ec2()->describeSecurityGroups()['SecurityGroups'];
    }

    /**
     * @throws ResourceDoesNotExistException
     */
    public static function loadBalancerSecurityGroup(): array
    {
        return static::securityGroupByName(SecurityGroup::LOAD_BALANCER_SECURITY_GROUP);
    }

    /**
     * @throws ResourceDoesNotExistException
     */
    public static function ec2SecurityGroup(): array
    {
        return static::securityGroupByName(Manifest::get('aws.ec2.security-group', SecurityGroup::EC2_SECURITY_GROUP));
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

    public static function loadBalancer(): array
    {
        $loadBalancers = Aws::elasticLoadBalancingV2()->describeLoadBalancers();

        foreach ($loadBalancers['LoadBalancers'] as $loadBalancer) {
            if ($loadBalancer['LoadBalancerName'] === Manifest::get('aws.alb')) {
                return $loadBalancer;
            }
        }

        throw new ResourceDoesNotExistException("Could not find load balancer");
    }

    public static function targetGroup(): array
    {
        $targetGroups = Aws::elasticLoadBalancingV2()->describeTargetGroups([
            'LoadBalancerArn' => static::loadBalancer()['LoadBalancerArn'],
        ])['TargetGroups'];

        if (count($targetGroups) === 0) {
            throw new ResourceDoesNotExistException(sprintf("Could not find target group for ALB %s", static::loadBalancer()['LoadBalancerName']));
        }

        return $targetGroups[0];
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

    public static function launchTemplate(): array
    {
        $launchTemplates = Aws::ec2()->describeLaunchTemplates([
            'Filters' => [
                [
                    'Name' => 'launch-template-name',
                    'Values' => [Helpers::keyedResourceName(exclusive: false)],
                ],
            ],
        ])['LaunchTemplates'];

        if (count($launchTemplates) === 0) {
            throw ResourceDoesNotExistException::make(sprintf("Could not find launch template %s", Helpers::keyedResourceName()))
                ->suggest('compute:sync');
        }

        return $launchTemplates[0];
    }

    public static function launchTemplatePayload(): array
    {
        return [
            'LaunchTemplateName' => Helpers::keyedResourceName(exclusive: false),
            'LaunchTemplateData' => [
                'IamInstanceProfile' => [
                    'Name' => Manifest::get('aws.ec2.instance-profile', Helpers::keyedResourceName(Iam::INSTANCE_PROFILE, exclusive: false)),
                ],
                'InstanceType' => Manifest::get('aws.ec2.instance-type'),
                'KeyName' => Manifest::get('aws.ec2.key-pair', Helpers::keyedResourceName(exclusive: false)),
                'SecurityGroupIds' => [
                    AwsResources::ec2SecurityGroup()['GroupId'],
                ],
                'Monitoring' => [
                    'Enabled' => true,
                ],
            ],
            'TagSpecifications' => [
                [
                    'ResourceType' => 'launch-template',
                    ...Aws::tags([
                        'Name' => Helpers::keyedResourceName(),
                    ]),
                ],
            ],
        ];
    }

    public static function vpc(): array
    {
        $name = Manifest::get('aws.vpc', Helpers::keyedResourceName(exclusive: false));

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

        return $vpcs[0];
    }

    public static function subnets(): array
    {
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

        return $subnets;
    }

    public static function keyPair(): array
    {
        $name = Manifest::get('aws.ec2.key-pair', Helpers::keyedResourceName(exclusive: false));

        foreach (Aws::ec2()->describeKeyPairs()['KeyPairs'] as $keyPair) {
            if ($keyPair['KeyName'] === $name) {
                return $keyPair;
            }
        }

        throw ResourceDoesNotExistException::make("Could not find key pair with name $name")
            ->suggest('sync:network');
    }
}
