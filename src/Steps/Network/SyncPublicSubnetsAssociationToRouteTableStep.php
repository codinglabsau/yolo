<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\PublicSubnets;

class SyncPublicSubnetsAssociationToRouteTableStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        // note: there does not appear to be a way to retrieve this resource directly, and
        // calling associateRouteTable() multiple times does not create additional associations. This
        // resource is visible in the AWS console under VPC -> Route Tables -> Subnet associations.
        if (! Arr::get($options, 'dry-run')) {
            foreach (static::cases() as $publicSubnetName) {
                Aws::ec2()->associateRouteTable([
                    'RouteTableId' => AwsResources::routeTable()['RouteTableId'],
                    'SubnetId' => AwsResources::subnetByName($publicSubnetName, relative: Manifest::doesntHave('aws.public-subnets'))['SubnetId'],
                ]);
            }

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }

    protected static function cases(): array
    {
        if (Manifest::has('aws.public-subnets')) {
            return Manifest::get('aws.public-subnets');
        }

        return array_map(fn ($case) => $case->value, PublicSubnets::cases());
    }
}
