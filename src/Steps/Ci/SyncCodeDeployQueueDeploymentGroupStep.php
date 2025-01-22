<?php

namespace Codinglabs\Yolo\Steps\Ci;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncCodeDeployQueueDeploymentGroupStep implements Step
{
    use UsesCodeDeploy;

    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::queueDeploymentGroup();
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::codeDeploy()->createDeploymentGroup([
                    ...static::deploymentGroupPayload(),
                    ...[
                        'deploymentGroupName' => Helpers::keyedResourceName('queue'),
                        'deploymentConfigName' => 'CodeDeployDefault.AllAtOnce',
                        'autoScalingGroups' => [
                            AwsResources::autoScalingGroupQueue()['AutoScalingGroupName'],
                        ],
                        'deploymentStyle' => [
                            'deploymentType' => 'IN_PLACE',
                            'deploymentOption' => 'WITHOUT_TRAFFIC_CONTROL',
                        ],
                    ],
                    ...Aws::tags([
                        'Name' => Helpers::keyedResourceName('scheduler'),
                    ]),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
