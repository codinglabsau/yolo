<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEc2SecurityGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (Manifest::get('aws.ec2.security-group', false)) {
            return StepResult::SKIPPED;
        }

        try {
            AwsResources::ec2SecurityGroup();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName(SecurityGroup::EC2_SECURITY_GROUP, exclusive: false);

                Aws::ec2()->createSecurityGroup([
                    'Description' => 'Enable load balancer and SSH traffic',
                    'GroupName' => $name,
                    'VpcId' => AwsResources::vpc()['VpcId'],
                    'TagSpecifications' => [
                        [
                            'ResourceType' => 'security-group',
                            ...Aws::tags([
                                'Name' => $name,
                            ])
                        ],
                    ],
                ]);

                $securityGroup = AwsResources::ec2SecurityGroup();
                $publicIp = file_get_contents("https://api.ipify.org");

                Aws::ec2()->authorizeSecurityGroupIngress([
                    'GroupId' => $securityGroup['GroupId'],
                    'IpPermissions' => [
                        [
                            // allow HTTP ingress from the load balancer
                            'IpProtocol' => 'tcp',
                            'FromPort' => 80,
                            'ToPort' => 80,
                            'UserIdGroupPairs' => [
                                [
                                    'GroupId' => AwsResources::loadBalancerSecurityGroup()['GroupId'],
                                    'Description' => 'HTTP ingress from the load balancer',
                                ],
                            ],
                        ],
                        [
                            // allow SSH from the current IP
                            'IpProtocol' => 'tcp',
                            'FromPort' => 22,
                            'ToPort' => 22,
                            'IpRanges' => [
                                [
                                    'CidrIp' => "$publicIp/32",
                                    'Description' => 'YOLO-determined public IP during sync. Delete if unused.'
                                ],
                            ],
                        ],
                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
