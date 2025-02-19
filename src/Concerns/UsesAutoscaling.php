<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesAutoscaling
{
    public static function autoScalingGroupWeb(): array
    {
        return static::autoScalingGroup(Manifest::get('aws.autoscaling.web'));
    }

    public static function autoScalingGroupQueue(): array
    {
        return static::autoScalingGroup(Manifest::get('aws.autoscaling.queue'));
    }

    public static function autoScalingGroupScheduler(): array
    {
        return static::autoScalingGroup(Manifest::get('aws.autoscaling.scheduler'));
    }

    protected static function autoScalingGroup(string $name): array
    {
        $autoScalingGroups = Aws::autoscaling()->describeAutoScalingGroups()['AutoScalingGroups'];

        foreach ($autoScalingGroups as $autoScalingGroup) {
            if ($autoScalingGroup['AutoScalingGroupName'] === $name) {
                return $autoScalingGroup;
            }
        }

        throw new ResourceDoesNotExistException("Could not find auto scaling group named $name");
    }

    public static function autoScalingGroupWebScaleUpPolicy(): array
    {
        return collect(static::autoScalingGroupWebScalingPolicies())
            ->first(fn ($policy) => Str::endsWith($policy['PolicyName'], '-up'));
    }

    public static function autoScalingGroupWebScaleDownPolicy(): array
    {
        return collect(static::autoScalingGroupWebScalingPolicies())
            ->first(fn ($policy) => Str::endsWith($policy['PolicyName'], '-down'));
    }

    protected static function autoScalingGroupWebScalingPolicies(): array
    {
        return static::autoScalingGroupScalingPolicies(Manifest::get('aws.autoscaling.web'));
    }

    protected static function autoScalingGroupScalingPolicies(string $asgName): array
    {
        $autoScalingGroupScalingPolicies = Aws::autoscaling()->describePolicies(
            [
                'AutoScalingGroupName' => $asgName,
            ]
        )['ScalingPolicies'];

        if (count($autoScalingGroupScalingPolicies) === 0) {
            throw new ResourceDoesNotExistException(sprintf("Could not find asg scaling policies %s", $asgName));
        }

        return $autoScalingGroupScalingPolicies;
    }
}
