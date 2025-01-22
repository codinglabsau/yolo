<?php

namespace Codinglabs\Yolo\Steps\Ci;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncCodeDeploySchedulerDeploymentGroupStep implements Step
{
    use UsesCodeDeploy;

    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::schedulerDeploymentGroup();
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::codeDeploy()->createDeploymentGroup([
                    ...static::deploymentGroupPayload(),
                    ...[
                        'deploymentGroupName' => Helpers::keyedResourceName('scheduler'),
                        'deploymentConfigName' => 'CodeDeployDefault.AllAtOnce',
                        'autoScalingGroups' => [
                            AwsResources::autoScalingGroupScheduler()['AutoScalingGroupName'],
                        ],
                        'deploymentStyle' => [
                            'deploymentType' => 'IN_PLACE',
                            'deploymentOption' => 'WITHOUT_TRAFFIC_CONTROL',
                        ],
                    ]]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
