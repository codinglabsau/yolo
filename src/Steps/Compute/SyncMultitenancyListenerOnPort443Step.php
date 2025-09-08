<?php

namespace Codinglabs\Yolo\Steps\Compute;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncMultitenancyListenerOnPort443Step implements ExecutesMultitenancyStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::loadBalancerListenerOnPort(443);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::elasticLoadBalancingV2()->createListener([
                    'LoadBalancerArn' => AwsResources::loadBalancer()['LoadBalancerArn'],
                    'Protocol' => 'HTTPS',
                    'Port' => 443,
                    'Certificates' => [
                        [
                            'CertificateArn' => AwsResources::certificate(Manifest::tenants()[0]['apex'])['CertificateArn'],
                        ],
                    ],
                    'DefaultActions' => [
                        [
                            'Type' => 'forward',
                            'TargetGroupArn' => AwsResources::targetGroup()['TargetGroupArn'],
                        ],
                    ],
                    ...Aws::tags([
                        'Name' => Helpers::keyedResourceName(exclusive: false),
                    ]),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
