<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class ElbV2
{
    /** @var array<string, array<string, mixed>> */
    protected static array $loadBalancers = [];

    /** @var array<string, array<string, mixed>> */
    protected static array $targetGroups = [];

    /** @var array<string, array<string, mixed>> */
    protected static array $listeners = [];

    public static function loadBalancer(string $name, bool $refresh = false): array
    {
        if (! $refresh && isset(static::$loadBalancers[$name])) {
            return static::$loadBalancers[$name];
        }

        $loadBalancers = Aws::elasticLoadBalancingV2()->describeLoadBalancers();

        foreach ($loadBalancers['LoadBalancers'] as $loadBalancer) {
            if ($loadBalancer['LoadBalancerName'] === $name) {
                return static::$loadBalancers[$name] = $loadBalancer;
            }
        }

        throw new ResourceDoesNotExistException("Could not find load balancer $name");
    }

    public static function targetGroup(string $name, bool $refresh = false): array
    {
        if (! $refresh && isset(static::$targetGroups[$name])) {
            return static::$targetGroups[$name];
        }

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

        return static::$targetGroups[$name] = $targetGroups[0];
    }

    public static function listenerOnPort(string $loadBalancerArn, int $port, bool $refresh = false): array
    {
        $key = "$loadBalancerArn::$port";

        if (! $refresh && isset(static::$listeners[$key])) {
            return static::$listeners[$key];
        }

        $listeners = Aws::elasticLoadBalancingV2()->describeListeners([
            'LoadBalancerArn' => $loadBalancerArn,
        ]);

        foreach ($listeners['Listeners'] as $listener) {
            if ($listener['Port'] === $port) {
                return static::$listeners[$key] = $listener;
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
}
