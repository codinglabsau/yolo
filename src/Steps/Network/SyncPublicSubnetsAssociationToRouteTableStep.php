<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\PublicSubnets;
use Codinglabs\Yolo\Resources\Ec2\RouteTable;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;

class SyncPublicSubnetsAssociationToRouteTableStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        // note: there does not appear to be a way to retrieve this resource directly, and
        // calling associateRouteTable() multiple times does not create additional associations. This
        // resource is visible in the AWS console under VPC -> Route Tables -> Subnet associations.
        if (! Arr::get($options, 'dry-run')) {
            $routeTableId = (new RouteTable())->arn();

            foreach (array_keys(PublicSubnets::cases()) as $index) {
                Aws::ec2()->associateRouteTable([
                    'RouteTableId' => $routeTableId,
                    'SubnetId' => (new PublicSubnet($index))->arn(),
                ]);
            }

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
