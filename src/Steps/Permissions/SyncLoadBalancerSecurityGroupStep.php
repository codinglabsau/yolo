<?php

namespace Codinglabs\Yolo\Steps\Permissions;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncLoadBalancerSecurityGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::application();
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::ec2()->createSecurityGroup([
                    'Description' => 'Enable HTTP and HTTPS from anywhere',
                    'GroupName' => Helpers::keyedResourceName('load-balancer-security-group', exclusive: false),
                    'VpcId' => AwsResources::vpc()['VpcId'],
                    'TagSpecifications' => [
                        [
                            'ResourceType' => 'load-balancer',
                            'Tags' => [
                                [
                                    'Key' => 'Name',
                                    'Value' => Helpers::keyedResourceName('load-balancer-security-group', exclusive: false),
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
