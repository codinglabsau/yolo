<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\AwsResources;
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
            ElbV2::loadBalancer($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElbV2::loadBalancer($this->name())['LoadBalancerArn'];
    }

    public function create(): void
    {
        Aws::elasticLoadBalancingV2()->createLoadBalancer([
            'Name' => $this->name(),
            'Type' => 'application',
            'Scheme' => 'internet-facing',
            'IpAddressType' => 'ipv4',
            // VPC subnets + LB security group still come from the legacy AwsResources facade —
            // those resources haven't been migrated yet. Covered by LPX-612.
            'SecurityGroups' => [
                AwsResources::loadBalancerSecurityGroup()['GroupId'],
            ],
            'Subnets' => AwsResources::publicSubnetIds(),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseElbV2Tags($this->arn(), $this->tags());
    }
}
