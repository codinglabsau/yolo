<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncHttpListenerStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::loadBalancerListenerOnPort(80);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::elasticLoadBalancingV2()->createListener([
                'LoadBalancerArn' => AwsResources::loadBalancer()['LoadBalancerArn'],
                'Protocol' => 'HTTP',
                'Port' => 80,
                'DefaultActions' => [
                    [
                        'Type' => 'redirect',
                        'RedirectConfig' => [
                            'Protocol' => 'HTTPS',
                            'Port' => '443',
                            'Host' => '#{host}',
                            'Path' => '/#{path}',
                            'Query' => '#{query}',
                            'StatusCode' => 'HTTP_301',
                        ],
                    ],
                ],
            ]);

            return StepResult::CREATED;
        }
    }
}
