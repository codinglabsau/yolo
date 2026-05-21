<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncHttpListenerStep implements ExecutesWebStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            $listener = AwsResources::loadBalancerListenerOnPort(80);

            if (! Arr::get($options, 'dry-run')) {
                Aws::synchroniseElbV2Tags($listener['ListenerArn'], ['Name' => static::name()]);
            }

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
                ...Aws::tags(['Name' => static::name()]),
            ]);

            return StepResult::CREATED;
        }
    }

    protected static function name(): string
    {
        return Helpers::keyedResourceName('http', exclusive: false);
    }
}
