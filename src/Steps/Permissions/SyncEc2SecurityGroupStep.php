<?php

namespace Codinglabs\Yolo\Steps\Permissions;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEc2SecurityGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::ec2SecurityGroup();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName('ec2-security-group', exclusive: false);

                Aws::ec2()->createSecurityGroup([
                    'Description' => 'Enable load balancer and SSH traffic',
                    'GroupName' => $name,
                    'VpcId' => AwsResources::vpc()['VpcId'],
                    'TagSpecifications' => [
                        [
                            'ResourceType' => 'security-group',
                            'Tags' => [
                                [
                                    'Key' => 'Name',
                                    'Value' => $name,
                                ],
                            ],
                        ],
                    ],
                ]);

                $securityGroup = AwsResources::ec2SecurityGroup();
                $publicIp = file_get_contents("https://api.ipify.org");

                Aws::ec2()->authorizeSecurityGroupIngress([
                    'GroupId' => $securityGroup['GroupId'],
                    'IpPermissions' => [
                        [
                            // allow port 80 traffic from the load balancer
                            'IpProtocol' => 'tcp',
                            'FromPort' => 80,
                            'ToPort' => 80,
                            'SourceSecurityGroupId' => AwsResources::loadBalancerSecurityGroup()['GroupId'], // todo: this is not working
                        ],
                        [
                            // allow SSH from the current IP
                            'IpProtocol' => 'tcp',
                            'FromPort' => 22,
                            'ToPort' => 22,
                            'IpRanges' => [['CidrIp' => "$publicIp/32", 'Description' => 'YOLO-determined public IP during sync. Delete if unused.']],
                        ],
                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
