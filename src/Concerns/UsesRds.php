<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Rds;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesRds
{
    public static function dbSubnetGroup(): array
    {
        $name = Manifest::has('aws.rds.subnet')
            ? Manifest::get('aws.rds.subnet')
            : Helpers::keyedResourceName(Rds::PUBLIC_SUBNET_GROUP);

        $dbSubnetGroups = Aws::rds()->describeDBSubnetGroups();

        foreach ($dbSubnetGroups['DBSubnetGroups'] as $dbSubnetGroup) {
            if ($dbSubnetGroup['DBSubnetGroupName'] === $name) {
                return $dbSubnetGroup;
            }
        }

        throw new ResourceDoesNotExistException("Could not find RDS Subnet Group with name $name");
    }
}
