<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncLoadBalancerSecurityGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::loadBalancerSecurityGroup();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName(SecurityGroup::LOAD_BALANCER_SECURITY_GROUP, exclusive: false);

                Aws::ec2()->createSecurityGroup([
                    'Description' => 'Enable HTTP and HTTPS from anywhere',
                    'GroupName' => $name,
                    'VpcId' => AwsResources::vpc()['VpcId'],
                    'TagSpecifications' => [
                        [
                            'ResourceType' => 'security-group',
                            ...Aws::tags([
                                'Name' => $name,
                            ]),
                        ],
                    ],
                ]);

                $securityGroup = AwsResources::loadBalancerSecurityGroup();

                // Authorize ingress for HTTP (port 80)
                Aws::ec2()->authorizeSecurityGroupIngress([
                    'GroupId' => $securityGroup['GroupId'],
                    'IpPermissions' => [
                        [
                            'IpProtocol' => 'tcp',
                            'FromPort' => 80,
                            'ToPort' => 80,
                            'IpRanges' => [['CidrIp' => '0.0.0.0/0']],
                        ],
                        [
                            'IpProtocol' => 'tcp',
                            'FromPort' => 443,
                            'ToPort' => 443,
                            'IpRanges' => [['CidrIp' => '0.0.0.0/0']],
                        ],
                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
