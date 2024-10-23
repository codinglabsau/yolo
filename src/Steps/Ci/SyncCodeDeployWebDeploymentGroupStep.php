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

class SyncCodeDeployWebDeploymentGroupStep implements Step
{
    use UsesCodeDeploy;

    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::webDeploymentGroup();
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::codeDeploy()->createDeploymentGroup([
                    ...static::deploymentGroupPayload(),
                    ...[
                        'deploymentGroupName' => Helpers::keyedResourceName('web'),
                        'deploymentConfigName' => 'OneThirdAtATime',
                        'autoScalingGroups' => [
                            AwsResources::autoScalingGroupWeb()['AutoScalingGroupName'],
                        ],
                        'deploymentStyle' => [
                            'deploymentType' => 'IN_PLACE',
                            'deploymentOption' => 'WITH_TRAFFIC_CONTROL',
                        ],
                        'loadBalancerInfo' => [
                            'targetGroupInfoList' => [
                                [
                                    'name' => AwsResources::targetGroup()['TargetGroupName'],
                                ],
                            ],
                        ],
                    ]]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
