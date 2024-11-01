<?php

namespace Codinglabs\Yolo\Steps\Compute;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncTargetGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::targetGroup();
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::elasticLoadBalancingV2()->createTargetGroup([
                    'VpcId' => AwsResources::vpc()['VpcId'],
                    'Name' => Helpers::keyedResourceName(exclusive: false),
                    'Port' => 80,
                    'Protocol' => 'HTTP',
                    'HealthyThresholdCount' => 2,
                    'UnhealthyThresholdCount' => 2,
                    'HealthCheckEnabled' => true,
                    'HealthCheckIntervalSeconds' => 10,
                    'HealthCheckPath' => '/healthy',
                    'HealthCheckTimeoutSeconds' => 5,
                    ...Aws::tags([
                        'Name' => Helpers::keyedResourceName(exclusive: false)
                    ]),
                ]);

                Aws::elasticLoadBalancingV2()->modifyTargetGroupAttributes([
                    'TargetGroupArn' => AwsResources::targetGroup()['TargetGroupArn'],
                    'Attributes' => [
                        [
                            'Key' => 'deregistration_delay.timeout_seconds',
                            'Value' => '30',
                        ],
                        [
                            'Key' => 'stickiness.enabled',
                            'Value' => 'true',
                        ],
                        [
                            'Key' => 'stickiness.type',
                            'Value' => 'lb_cookie',
                        ],
                        [
                            'Key' => 'stickiness.lb_cookie.duration_seconds',
                            'Value' => '30',
                        ],
                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
