<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Network\PublicSubnet;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\Network\LoadBalancerSecurityGroup;

class LoadBalancer implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get('aws.alb', Helpers::keyedResourceName(exclusive: false));
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
            'SecurityGroups' => [
                (new LoadBalancerSecurityGroup())->arn(),
            ],
            'Subnets' => PublicSubnet::ids(),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseElbV2Tags($this->arn(), $this->tags());
    }
}
