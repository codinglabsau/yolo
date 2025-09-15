<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
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

        $targetGroups = Aws::elasticLoadBalancingV2()->describeTargetGroups();

        foreach ($targetGroups['TargetGroups'] as $targetGroup) {
            if ($targetGroup['TargetGroupName'] === Helpers::keyedResourceName(exclusive: false)) {
                static::$targetGroup = $targetGroup;

                return $targetGroup;
            }
        }

        throw new ResourceDoesNotExistException(sprintf('Could not find target group matching name %s', Helpers::keyedResourceName(exclusive: false)));
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
