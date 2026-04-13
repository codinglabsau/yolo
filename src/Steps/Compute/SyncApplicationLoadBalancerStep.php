<?php

namespace Codinglabs\Yolo\Steps\Compute;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncApplicationLoadBalancerStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::loadBalancer();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::elasticLoadBalancingV2()->createLoadBalancer([
                    'Name' => Helpers::keyedResourceName(exclusive: false),
                    'SecurityGroups' => [AwsResources::loadBalancerSecurityGroup()['GroupId']],
                    'Subnets' => collect(AwsResources::subnets())
                        ->pluck('SubnetId')
                        ->toArray(),
                    ...Aws::tags([
                        'Name' => Helpers::keyedResourceName(exclusive: false),
                    ]),
                ]);

                for ($i = 0; $i < 60; $i++) {
                    $loadBalancer = AwsResources::loadBalancer();

                    if ($loadBalancer['State']['Code'] === 'active') {
                        break;
                    }

                    sleep(3);
                }

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
