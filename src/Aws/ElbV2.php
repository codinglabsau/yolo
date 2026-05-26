<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class ElbV2
{
    public static function loadBalancer(string $name): array
    {
        $loadBalancers = Aws::elasticLoadBalancingV2()->describeLoadBalancers();

        foreach ($loadBalancers['LoadBalancers'] as $loadBalancer) {
            if ($loadBalancer['LoadBalancerName'] === $name) {
                return $loadBalancer;
            }
        }

        throw new ResourceDoesNotExistException("Could not find load balancer $name");
    }

    public static function targetGroup(string $name): array
    {
        try {
            $targetGroups = Aws::elasticLoadBalancingV2()->describeTargetGroups([
                'Names' => [$name],
            ])['TargetGroups'];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'TargetGroupNotFound') {
                throw new ResourceDoesNotExistException("Could not find target group $name");
            }

            throw $e;
        }

        if (count($targetGroups) === 0) {
            throw new ResourceDoesNotExistException("Could not find target group $name");
        }

        return $targetGroups[0];
    }

    public static function listenerOnPort(string $loadBalancerArn, int $port): array
    {
        $listeners = Aws::elasticLoadBalancingV2()->describeListeners([
            'LoadBalancerArn' => $loadBalancerArn,
        ]);

        foreach ($listeners['Listeners'] as $listener) {
            if ($listener['Port'] === $port) {
                return $listener;
            }
        }

        throw new ResourceDoesNotExistException("Could not find listener on port $port");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function rules(string $listenerArn): array
    {
        return Aws::elasticLoadBalancingV2()->describeRules([
            'ListenerArn' => $listenerArn,
        ])['Rules'];
    }

    public static function listenerCertificate(string $listenerArn, string $certificateArn): array
    {
        $certificates = Aws::elasticLoadBalancingV2()->describeListenerCertificates([
            'ListenerArn' => $listenerArn,
        ])['Certificates'];

        foreach ($certificates as $certificate) {
            if ($certificate['CertificateArn'] === $certificateArn) {
                return $certificate;
            }
        }

        throw new ResourceDoesNotExistException("Could not find listener certificate on listener $listenerArn");
    }
}
