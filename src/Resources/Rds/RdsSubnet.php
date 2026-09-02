<?php

namespace Codinglabs\Yolo\Resources\Rds;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Enums\Rds as RdsEnum;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ec2\PrivateSubnet;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Spans the private subnets only, so a database never lands in a public subnet.
 */
class RdsSubnet implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(RdsEnum::PRIVATE_SUBNET_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::Env;
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
            'SubnetIds' => PrivateSubnet::ids(),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseRdsTags($this->arn(), $this->tags(), $apply);
    }

    /** AWS refuses to delete a subnet group still referenced by a DB instance. */
    public function delete(): void
    {
        try {
            Aws::rds()->deleteDBSubnetGroup(['DBSubnetGroupName' => $this->name()]);
        } catch (RdsException $e) {
            if ($e->getAwsErrorCode() === 'DBSubnetGroupNotFoundFault') {
                return;
            }

            throw $e;
        }
    }
}
