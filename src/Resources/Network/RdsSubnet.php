<?php

namespace Codinglabs\Yolo\Resources\Network;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\AppScoped;
use Codinglabs\Yolo\Enums\Rds as RdsEnum;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * RDS DB subnet group spanning every subnet in the VPC, so a database can be
 * launched into the YOLO network. Point `aws.rds.subnet` at an existing group
 * name to adopt one instead.
 */
class RdsSubnet implements AppScoped, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get('aws.rds.subnet', Helpers::keyedResourceName(RdsEnum::PUBLIC_SUBNET_GROUP));
    }

    public function exists(): bool
    {
        try {
            Rds::subnetGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Rds::subnetGroup($this->name())['DBSubnetGroupArn'];
    }

    public function create(): void
    {
        Aws::rds()->createDBSubnetGroup([
            'DBSubnetGroupName' => $this->name(),
            'DBSubnetGroupDescription' => 'YOLO private subnet group',
            'SubnetIds' => collect(Ec2::vpcSubnets((new Vpc())->arn()))->pluck('SubnetId')->all(),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseRdsTags($this->arn(), $this->tags());
    }
}
