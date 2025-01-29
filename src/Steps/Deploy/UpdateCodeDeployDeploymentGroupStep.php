<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;

class UpdateCodeDeployDeploymentGroupStep implements Step
{
    use UsesCodeDeploy;

    public function __invoke(): void
    {
        Aws::codeDeploy()->updateDeploymentGroup([
            'applicationName' => static::applicationName(),
            'currentDeploymentGroupName' => Helpers::keyedResourceName(ServerGroup::WEB),
            'autoScalingGroups' => [
                Manifest::get('aws.autoscaling.web'),
            ],
        ]);

        Aws::codeDeploy()->updateDeploymentGroup([
            'applicationName' => static::applicationName(),
            'currentDeploymentGroupName' => Helpers::keyedResourceName(ServerGroup::QUEUE),
            'autoScalingGroups' => [
                Manifest::get('aws.autoscaling.queue'),
            ],
        ]);

        Aws::codeDeploy()->updateDeploymentGroup([
            'applicationName' => static::applicationName(),
            'currentDeploymentGroupName' => Helpers::keyedResourceName(ServerGroup::SCHEDULER),
            'autoScalingGroups' => [
                Manifest::get('aws.autoscaling.scheduler'),
            ],
        ]);
    }
}
