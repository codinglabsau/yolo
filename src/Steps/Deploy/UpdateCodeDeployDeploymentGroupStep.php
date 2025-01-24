<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\DeploymentGroups;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;

class UpdateCodeDeployDeploymentGroupStep implements Step
{
    use UsesCodeDeploy;

    public function __invoke(): void
    {
        Aws::codeDeploy()->updateDeploymentGroup([
            'applicationName' => static::applicationName(),
            'currentDeploymentGroupName' => Helpers::keyedResourceName(DeploymentGroups::WEB),
            'autoScalingGroups' => [
                Manifest::get('aws.autoscaling.web'),
            ],
        ]);

        Aws::codeDeploy()->updateDeploymentGroup([
            'applicationName' => static::applicationName(),
            'currentDeploymentGroupName' => Helpers::keyedResourceName(DeploymentGroups::QUEUE),
            'autoScalingGroups' => [
                Manifest::get('aws.autoscaling.queue'),
            ],
        ]);

        Aws::codeDeploy()->updateDeploymentGroup([
            'applicationName' => static::applicationName(),
            'currentDeploymentGroupName' => Helpers::keyedResourceName(DeploymentGroups::SCHEDULER),
            'autoScalingGroups' => [
                Manifest::get('aws.autoscaling.scheduler'),
            ],
        ]);
    }
}
