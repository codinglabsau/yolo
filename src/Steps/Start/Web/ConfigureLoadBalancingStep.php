<?php

namespace Codinglabs\Yolo\Steps\Start\Web;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;
use Codinglabs\Yolo\Concerns\UsesAutoscaling;

class ConfigureLoadBalancingStep implements RunsOnAwsWeb
{
    use UsesAutoscaling;

    public function __invoke(): void
    {
        // ensure the web ASG is attached to the ALB
        $asgWeb = AwsResources::autoScalingGroupWeb();

        Aws::autoscaling()->attachTrafficSources([
            'AutoScalingGroupName' => $asgWeb['AutoScalingGroupName'],
            'TrafficSources' => [
                [
                    'Identifier' => AwsResources::targetGroup()['TargetGroupArn'],
                    'Type' => 'elbv2',
                ],
            ],
        ]);

        Aws::autoscaling()->updateAutoScalingGroup([
            'AutoScalingGroupName' => $asgWeb['AutoScalingGroupName'],
            'HealthCheckType' => 'ELB',
            'HealthCheckGracePeriod' => 60,
        ]);
    }
}
