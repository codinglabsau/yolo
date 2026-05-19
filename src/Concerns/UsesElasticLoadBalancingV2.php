<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesElasticLoadBalancingV2
{
    protected static array $loadBalancer;

    protected static array $targetGroup;

    public static function loadBalancer($refresh = false): array
    {
        if (! $refresh && isset(static::$loadBalancer)) {
            return static::$loadBalancer;
        }

        $loadBalancers = Aws::elasticLoadBalancingV2()->describeLoadBalancers();

        foreach ($loadBalancers['LoadBalancers'] as $loadBalancer) {
            if ($loadBalancer['LoadBalancerName'] === Manifest::get('aws.alb', Helpers::keyedResourceName(exclusive: false))) {
                static::$loadBalancer = $loadBalancer;

                return $loadBalancer;
            }
        }

        throw new ResourceDoesNotExistException('Could not find load balancer');
    }

    public static function targetGroup(): array
    {
        if (isset(static::$targetGroup)) {
            return static::$targetGroup;
        }

        $name = Helpers::keyedResourceName(exclusive: true);

        try {
            $targetGroups = Aws::elasticLoadBalancingV2()->describeTargetGroups([
                'Names' => [$name],
            ])['TargetGroups'];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'TargetGroupNotFound') {
                throw new ResourceDoesNotExistException(sprintf('Could not find target group matching name %s', $name));
            }

            throw $e;
        }

        if (count($targetGroups) === 0) {
            throw new ResourceDoesNotExistException(sprintf('Could not find target group matching name %s', $name));
        }

        static::$targetGroup = $targetGroups[0];

        return static::$targetGroup;
    }

    public static function loadBalancerListenerOnPort(int $port): array
    {
        $listeners = Aws::elasticLoadBalancingV2()->describeListeners([
            'LoadBalancerArn' => static::loadBalancer()['LoadBalancerArn'],
        ]);

        foreach ($listeners['Listeners'] as $listener) {
            if ($listener['Port'] === $port) {
                return $listener;
            }
        }

        throw new ResourceDoesNotExistException("Could not find listener on port $port");
    }

    public static function listenerCertificate(string $listenerArn, string $certificateArn): array
    {
        $listenerCertificates = Aws::elasticLoadBalancingV2()->describeListenerCertificates([
            'ListenerArn' => $listenerArn,
        ]);

        foreach ($listenerCertificates['Certificates'] as $listenerCertificate) {
            if ($listenerCertificate['CertificateArn'] === $certificateArn) {
                return $listenerCertificate;
            }
        }

        throw new ResourceDoesNotExistException("Could not find listener certificate on listener $listenerArn");
    }
}
