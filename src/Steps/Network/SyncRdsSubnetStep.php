<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Rds;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncRdsSubnetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::dbSubnetGroup();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::rds()->createDBSubnetGroup([
                    'DBSubnetGroupName' => Helpers::keyedResourceName(Rds::PUBLIC_SUBNET_GROUP),
                    'DBSubnetGroupDescription' => 'YOLO private subnet group',
                    'SubnetIds' => collect(AwsResources::subnets())
                        ->pluck('SubnetId')
                        ->toArray(),
                    ...Aws::tags([
                        'Name' => Helpers::keyedResourceName(Rds::PUBLIC_SUBNET_GROUP),
                    ]),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
