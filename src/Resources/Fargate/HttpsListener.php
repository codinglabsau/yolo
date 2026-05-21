<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class HttpsListener implements Resource
{
    public function __construct(private array $certificate) {}

    public function name(): string
    {
        return Helpers::keyedResourceName('https', exclusive: false);
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            AwsResources::loadBalancerListenerOnPort(443);

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return AwsResources::loadBalancerListenerOnPort(443)['ListenerArn'];
    }

    public function create(): void
    {
        Aws::elasticLoadBalancingV2()->createListener([
            'LoadBalancerArn' => AwsResources::loadBalancer()['LoadBalancerArn'],
            'Protocol' => 'HTTPS',
            'Port' => 443,
            'SslPolicy' => 'ELBSecurityPolicy-TLS13-1-2-2021-06',
            'Certificates' => [
                ['CertificateArn' => $this->certificate['CertificateArn']],
            ],
            'DefaultActions' => [
                [
                    'Type' => 'fixed-response',
                    'FixedResponseConfig' => [
                        'StatusCode' => '503',
                        'ContentType' => 'text/plain',
                        'MessageBody' => 'No application matched the host header.',
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
