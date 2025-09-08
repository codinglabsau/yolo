<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\ServerGroup;

trait ConfiguresAutoScalingGroups
{
    protected static function createAutoScalingGroup(ServerGroup $serverGroup): string
    {
        $name = Helpers::keyedResourceName(
            sprintf('%s-%s', $serverGroup->value, Str::random(8)),
            exclusive: false
        );

        Aws::autoscaling()->createAutoScalingGroup([
            ...static::autoScalingGroupPayload($serverGroup),
            ...[
                'AutoScalingGroupName' => $name,
                'MinSize' => 1,
                'MaxSize' => 1,
                'DesiredCapacity' => 1,
                'Tags' => [
                    [
                        'Key' => 'Name',
                        'PropagateAtLaunch' => true,
                        'Value' => Helpers::keyedResourceName($serverGroup, exclusive: false),
                    ],
                    [
                        'Key' => 'yolo:environment',
                        'Value' => Helpers::app('environment'),
                        'PropagateAtLaunch' => true,
                    ],
                ],
            ],
        ]);

        Aws::autoscaling()->enableMetricsCollection([
            'AutoScalingGroupName' => $name,
            'Granularity' => '1Minute',
        ]);

        return $name;
    }

    protected static function updateAutoScalingGroup(ServerGroup $serverGroup): void
    {
        $autoScalingGroup = match ($serverGroup) {
            ServerGroup::WEB => AwsResources::autoScalingGroupWeb(),
            ServerGroup::QUEUE => AwsResources::autoScalingGroupQueue(),
            ServerGroup::SCHEDULER => AwsResources::autoScalingGroupScheduler(),
        };

        Aws::autoscaling()->updateAutoScalingGroup([
            'AutoScalingGroupName' => $autoScalingGroup['AutoScalingGroupName'],
            ...static::autoScalingGroupPayload($serverGroup),
        ]);
    }

    private static function autoScalingGroupPayload(ServerGroup $serverGroup): array
    {
        $payload = [
            'VPCZoneIdentifier' => collect(AwsResources::subnets())
                ->pluck('SubnetId')
                ->implode(','),
            'DefaultCooldown' => 60,
            'HealthCheckGracePeriod' => 60,
        ];

        $launchTemplatePayload = [
            'LaunchTemplateId' => AwsResources::launchTemplate()['LaunchTemplateId'],
            'Version' => AwsResources::launchTemplate()['LatestVersionNumber'],
        ];

        // check if a special ec2 instance type is configured
        $instanceType = Manifest::get(
            sprintf('aws.ec2.%s-instance-type', $serverGroup->value),
            Manifest::get('aws.ec2.instance-type')
        );

        if ($instanceType !== Manifest::get('aws.ec2.instance-type')) {
            // when a special instance type is configured, prepare a special payload
            return [
                ...$payload,
                'MixedInstancesPolicy' => [
                    'LaunchTemplate' => [
                        'LaunchTemplateSpecification' => $launchTemplatePayload,
                        'Overrides' => [
                            [
                                'InstanceType' => $instanceType,
                            ],
                        ],
                    ],
                ],
            ];
        }

        return [
            ...$payload,
            'LaunchTemplate' => $launchTemplatePayload,
        ];
    }
}
