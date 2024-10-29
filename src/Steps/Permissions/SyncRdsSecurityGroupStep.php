<?php

namespace Codinglabs\Yolo\Steps\Permissions;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroups;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncRdsSecurityGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::rdsSecurityGroup();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName(SecurityGroups::RDS_SECURITY_GROUP, exclusive: false);

                Aws::ec2()->createSecurityGroup([
                    'Description' => 'Enable EC2 to connect to RDS',
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

                $securityGroup = AwsResources::rdsSecurityGroup();

                Aws::ec2()->authorizeSecurityGroupIngress([
                    'GroupId' => $securityGroup['GroupId'],
                    'IpPermissions' => [
                        [
                            // Enable EC2 to connect to RDS
                            'IpProtocol' => 'tcp',
                            'FromPort' => 3306,
                            'ToPort' => 3306,
                            'UserIdGroupPairs' => [
                                [
                                    'GroupId' => AwsResources::ec2SecurityGroup()['GroupId'],
                                    'Description' => 'Enable EC2 to connect to RDS',
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
