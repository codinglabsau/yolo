<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class LoadBalancer implements Resource
{
    public function name(): string
    {
        return Manifest::get('aws.alb', Helpers::keyedResourceName(exclusive: false));
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            AwsLookups::loadBalancer();

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return AwsLookups::loadBalancer()['LoadBalancerArn'];
    }

    public function create(): void
    {
        Aws::elasticLoadBalancingV2()->createLoadBalancer([
            'Name' => $this->name(),
            'Type' => 'application',
            'Scheme' => 'internet-facing',
            'IpAddressType' => 'ipv4',
            'SecurityGroups' => [
                AwsLookups::loadBalancerSecurityGroup()['GroupId'],
            ],
            'Subnets' => AwsLookups::publicSubnetIds(),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseElbV2Tags($this->arn(), $this->tags());
    }
}
