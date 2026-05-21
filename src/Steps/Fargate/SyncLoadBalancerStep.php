<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncLoadBalancerStep implements ExecutesWebStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            $loadBalancer = AwsResources::loadBalancer();

            if (! Arr::get($options, 'dry-run')) {
                $name = Manifest::get('aws.alb', Helpers::keyedResourceName(exclusive: false));
                Aws::synchroniseElbV2Tags($loadBalancer['LoadBalancerArn'], ['Name' => $name]);
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            $name = Manifest::get('aws.alb', Helpers::keyedResourceName(exclusive: false));

            Aws::elasticLoadBalancingV2()->createLoadBalancer([
                'Name' => $name,
                'Type' => 'application',
                'Scheme' => 'internet-facing',
                'IpAddressType' => 'ipv4',
                'SecurityGroups' => [
                    AwsResources::loadBalancerSecurityGroup()['GroupId'],
                ],
                'Subnets' => AwsResources::publicSubnetIds(),
                ...Aws::tags(['Name' => $name]),
            ]);

            return StepResult::CREATED;
        }
    }
}
