<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\ElbV2;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class HttpsListener implements Deletable, Resource
{
    use ResolvesTags;

    // The certificate is only needed to create the listener; teardown and lookups
    // (arn/exists/delete) don't use it, so it defaults to empty.
    public function __construct(private array $certificate = []) {}

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

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElbV2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Teardown when the environment is torn down: delete the listener. YOLO's
     * teardown order deletes the load balancer's listener rules first, so by the
     * time we get here the listener is unreferenced and a plain deleteListener is
     * correct. A concurrent not-found is tolerated.
     */
    public function delete(): void
    {
        try {
            Aws::elasticLoadBalancingV2()->deleteListener([
                'ListenerArn' => $this->arn(),
            ]);
        } catch (ResourceDoesNotExistException) {
            // arn() resolution raced a concurrent delete — already gone.
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ListenerNotFound') {
                return;
            }

            throw $e;
        }
    }
}
