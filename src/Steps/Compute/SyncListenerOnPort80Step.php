<?php

namespace Codinglabs\Yolo\Steps\Compute;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncListenerOnPort80Step implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::loadBalancerListenerOnPort(80);
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::elasticLoadBalancingV2()->createListener([
                    'LoadBalancerArn' => AwsResources::loadBalancer()['LoadBalancerArn'],
                    'Protocol' => 'HTTP',
                    'Port' => 80,
                    'DefaultActions' => [
                        [
                            'Type' => 'forward',
                            'TargetGroupArn' => AwsResources::targetGroup()['TargetGroupArn'],
                        ],
                    ],
                    ...Aws::tags([
                        'Name' => Helpers::keyedResourceName(exclusive: false)
                    ]),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
