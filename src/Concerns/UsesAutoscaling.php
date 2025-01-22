<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesAutoscaling
{
    protected static array $asgWeb;
    protected static array $asgQueue;
    protected static array $asgScheduler;
    protected static array $asgWebScalingPolicies;

    public static function autoScalingGroupWeb(): array
    {
        if (isset(static::$asgWeb)) {
            return static::$asgWeb;
        }

        return static::autoScalingGroup(Manifest::get('aws.autoscaling.web'));
    }

    public static function autoScalingGroupQueue(): array
    {
        if (isset(static::$asgQueue)) {
            return static::$asgQueue;
        }

        return static::autoScalingGroup(Manifest::get('aws.autoscaling.queue'));
    }

    public static function autoScalingGroupScheduler(): array
    {
        if (isset(static::$asgScheduler)) {
            return static::$asgScheduler;
        }

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
        if (isset(static::$asgWebScalingPolicies)) {
            return static::$asgWebScalingPolicies;
        }

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

    public static function autoScalingGroupPayload(): array
    {
        return [
            'VPCZoneIdentifier' => collect(AwsResources::subnets())
                ->pluck('SubnetId')
                ->implode(','),
            'LaunchTemplate' => [
                'LaunchTemplateId' => AwsResources::launchTemplate()['LaunchTemplateId'],
                'Version' => AwsResources::launchTemplate()['LatestVersionNumber'],
            ],
            'DefaultCooldown' => 60,
            'HealthCheckGracePeriod' => 60,
        ];
    }
}
