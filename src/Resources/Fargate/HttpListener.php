<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class HttpListener implements Resource
{
    public function name(): string
    {
        return Helpers::keyedResourceName('http', exclusive: false);
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            ElbV2::listenerOnPort((new LoadBalancer())->arn(), 80);

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElbV2::listenerOnPort((new LoadBalancer())->arn(), 80)['ListenerArn'];
    }

    public function create(): void
    {
        Aws::elasticLoadBalancingV2()->createListener([
            'LoadBalancerArn' => (new LoadBalancer())->arn(),
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
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseElbV2Tags($this->arn(), $this->tags());
    }
}
