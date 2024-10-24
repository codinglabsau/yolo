<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class SyncDefaultRouteStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        // note: there does not appear to be a way to retrieve this resource directly, and
        // calling createRoute() multiple times does not create additional resources. This
        // resource is visible in the AWS console under VPC -> Route Tables -> Routes.
        if (! Arr::get($options, 'dry-run')) {
            Aws::ec2()->createRoute([
                'DestinationCidrBlock' => '0.0.0.0/0',
                'GatewayId' => AwsResources::internetGateway()['InternetGatewayId'],
                'RouteTableId' => AwsResources::routeTable()['RouteTableId'],
            ]);

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
