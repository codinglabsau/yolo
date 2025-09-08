<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;
use Codinglabs\Yolo\Concerns\ParsesOnlyOption;

class UpdateCodeDeployDeploymentGroupStep implements Step
{
    use ParsesOnlyOption;
    use UsesCodeDeploy;

    public function __invoke(array $options): StepResult
    {
        if ($this->shouldRunOnGroup(ServerGroup::WEB, $options)) {
            Aws::codeDeploy()->updateDeploymentGroup([
                'applicationName' => static::applicationName(),
                'currentDeploymentGroupName' => Helpers::keyedResourceName(ServerGroup::WEB),
                'autoScalingGroups' => [
                    Manifest::get('aws.autoscaling.web'),
                ],
            ]);
        }

        if ($this->shouldRunOnGroup(ServerGroup::QUEUE, $options)) {
            Aws::codeDeploy()->updateDeploymentGroup([
                'applicationName' => static::applicationName(),
                'currentDeploymentGroupName' => Helpers::keyedResourceName(ServerGroup::QUEUE),
                'autoScalingGroups' => [
                    Manifest::get('aws.autoscaling.queue'),
                ],
            ]);
        }

        if ($this->shouldRunOnGroup(ServerGroup::SCHEDULER, $options)) {
            Aws::codeDeploy()->updateDeploymentGroup([
                'applicationName' => static::applicationName(),
                'currentDeploymentGroupName' => Helpers::keyedResourceName(ServerGroup::SCHEDULER),
                'autoScalingGroups' => [
                    Manifest::get('aws.autoscaling.scheduler'),
                ],
            ]);
        }

        return StepResult::SUCCESS;
    }
}
