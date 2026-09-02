<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Application Auto Scaling scales an ECS service's desired count — NOT EC2 Auto
 * Scaling. Every lookup is scoped to the one namespace + dimension YOLO scales.
 */
class ApplicationAutoScaling
{
    public const SERVICE_NAMESPACE = 'ecs';

    public const SCALABLE_DIMENSION = 'ecs:service:DesiredCount';

    /**
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

    /**
     * @return array<int, string>
     */
    public static function policyNames(string $resourceId): array
    {
        try {
            $policies = Aws::applicationAutoScaling()->describeScalingPolicies([
                'ServiceNamespace' => self::SERVICE_NAMESPACE,
                'ResourceId' => $resourceId,
                'ScalableDimension' => self::SCALABLE_DIMENSION,
            ])['ScalingPolicies'];
        } catch (AwsException) {
            return [];
        }

        return array_map(fn (array $policy) => $policy['PolicyName'], $policies);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function scalingPolicies(string $resourceId): array
    {
        try {
            return Aws::applicationAutoScaling()->describeScalingPolicies([
                'ServiceNamespace' => self::SERVICE_NAMESPACE,
                'ResourceId' => $resourceId,
                'ScalableDimension' => self::SCALABLE_DIMENSION,
            ])['ScalingPolicies'];
        } catch (AwsException) {
            return [];
        }
    }

    /**
     * Cascades to the scale-out / scale-in CloudWatch alarms the policy generated.
     */
    public static function deleteScalingPolicy(string $resourceId, string $policyName): void
    {
        Aws::applicationAutoScaling()->deleteScalingPolicy([
            'ServiceNamespace' => self::SERVICE_NAMESPACE,
            'ResourceId' => $resourceId,
            'ScalableDimension' => self::SCALABLE_DIMENSION,
            'PolicyName' => $policyName,
        ]);
    }
}
