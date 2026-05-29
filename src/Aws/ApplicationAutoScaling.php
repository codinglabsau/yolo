<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Thin per-service wrapper around the Application Auto Scaling SDK client — the
 * service that scales an ECS service's desired count (this is NOT EC2 Auto
 * Scaling / autoscaling groups). Every lookup is scoped to the one namespace +
 * dimension YOLO ever scales, and translates the SDK's not-found / cold-account
 * errors to the project's standard signal.
 */
class ApplicationAutoScaling
{
    public const SERVICE_NAMESPACE = 'ecs';

    public const SCALABLE_DIMENSION = 'ecs:service:DesiredCount';

    /**
     * The scalable target registered for an ECS service resource id
     * (service/{cluster}/{service}), or the standard not-found signal when none
     * is registered.
     *
     * @return array<string, mixed>
     */
    public static function scalableTarget(string $resourceId): array
    {
        try {
            $targets = Aws::applicationAutoScaling()->describeScalableTargets([
                'ServiceNamespace' => self::SERVICE_NAMESPACE,
                'ResourceIds' => [$resourceId],
                'ScalableDimension' => self::SCALABLE_DIMENSION,
            ])['ScalableTargets'];
        } catch (AwsException) {
            throw new ResourceDoesNotExistException("Could not find scalable target $resourceId");
        }

        foreach ($targets as $target) {
            return $target;
        }

        throw new ResourceDoesNotExistException("Could not find scalable target $resourceId");
    }

    /**
     * The named scaling policy on an ECS service resource id, or the standard
     * not-found signal when it isn't registered.
     *
     * @return array<string, mixed>
     */
    public static function scalingPolicy(string $resourceId, string $policyName): array
    {
        try {
            $policies = Aws::applicationAutoScaling()->describeScalingPolicies([
                'ServiceNamespace' => self::SERVICE_NAMESPACE,
                'ResourceId' => $resourceId,
                'ScalableDimension' => self::SCALABLE_DIMENSION,
            ])['ScalingPolicies'];
        } catch (AwsException) {
            throw new ResourceDoesNotExistException("Could not find scaling policy $policyName");
        }

        foreach ($policies as $policy) {
            if ($policy['PolicyName'] === $policyName) {
                return $policy;
            }
        }

        throw new ResourceDoesNotExistException("Could not find scaling policy $policyName");
    }
}
