<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class SyncPublicSubnetsAssociationToRouteTableStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        // note: there does not appear to be a way to retrieve this resource directly, and
        // calling associateRouteTable() multiple times does not create additional associations. This
        // resource is visible in the AWS console under VPC -> Route Tables -> Subnet associations.
        $publicSubnetNames = [
            'public-subnet-a',
            'public-subnet-b',
            'public-subnet-c',
        ];

        if (! Arr::get($options, 'dry-run')) {
            foreach ($publicSubnetNames as $publicSubnetName) {
                Aws::ec2()->associateRouteTable([
                    'RouteTableId' => AwsResources::routeTable()['RouteTableId'],
                    'SubnetId' => AwsResources::subnetByName($publicSubnetName)['SubnetId'],
                ]);
            }

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
