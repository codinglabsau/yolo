<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;

class UpdateCodeDeployDeploymentGroupStep implements Step
{
    use UsesCodeDeploy;

    public function __invoke(): void
    {
        Aws::codeDeploy()->updateDeploymentGroup([
            'applicationName' => static::applicationName(),
            'currentDeploymentGroupName' => Helpers::keyedResourceName('web'),
            'autoScalingGroups' => [
                Manifest::get('aws.autoscaling.web'),
            ],
        ]);

        Aws::codeDeploy()->updateDeploymentGroup([
            'applicationName' => static::applicationName(),
            'currentDeploymentGroupName' => Helpers::keyedResourceName('queue'),
            'autoScalingGroups' => [
                Manifest::get('aws.autoscaling.queue'),
            ],
        ]);

        Aws::codeDeploy()->updateDeploymentGroup([
            'applicationName' => static::applicationName(),
            'currentDeploymentGroupName' => Helpers::keyedResourceName('scheduler'),
            'autoScalingGroups' => [
                Manifest::get('aws.autoscaling.scheduler'),
            ],
        ]);
    }
}
