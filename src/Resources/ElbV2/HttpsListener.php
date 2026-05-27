<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class HttpsListener implements Resource
{
    use ResolvesTags;

    public function __construct(private array $certificate) {}

    public function name(): string
    {
        return $this->keyedName('https');
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443);

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElbV2::listenerOnPort((new LoadBalancer())->arn(), 443)['ListenerArn'];
    }

    public function create(): void
    {
        Aws::elasticLoadBalancingV2()->createListener([
            'LoadBalancerArn' => (new LoadBalancer())->arn(),
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
