<?php

namespace Codinglabs\Yolo\Steps\Ci;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\DeploymentGroups;
use Codinglabs\Yolo\Concerns\UsesCodeDeploy;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncCodeDeployWebDeploymentGroupStep implements Step
{
    use UsesCodeDeploy;

    public function __invoke(array $options): StepResult
    {
        try {
            $deploymentGroup = AwsResources::webDeploymentGroup();

            $differences = Helpers::payloadHasDifferences(
                expected: $this->payload(),
                actual: static::normaliseDeploymentGroupForComparison($deploymentGroup)
            );

            if (! Arr::get($options, 'dry-run')) {
                // always sync tags as they are not compared in the payload
                static::applyTagsToDeploymentGroup($deploymentGroup);

                if ($differences) {
                    Aws::codeDeploy()->updateDeploymentGroup([
                        'currentDeploymentGroupName' => $deploymentGroup['deploymentGroupName'],
                        ...$this->payload(),
                    ]);

                    return StepResult::SYNCED;
                }
            }

            return $differences
                ? StepResult::OUT_OF_SYNC
                : StepResult::IN_SYNC;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::codeDeploy()->createDeploymentGroup($this->payload());
                static::applyTagsToDeploymentGroup(AwsResources::webDeploymentGroup());

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }

    protected function payload(): array
    {
        return [
            ...static::deploymentGroupPayload(),
            ...[
                'deploymentGroupName' => Helpers::keyedResourceName(DeploymentGroups::WEB),
                'deploymentConfigName' => Manifest::get('aws.codedeploy.with-load-balancing', false)
                    ? 'OneThirdAtATime'
                    : 'CodeDeployDefault.AllAtOnce',
                'autoScalingGroups' => [
                    AwsResources::autoScalingGroupWeb()['AutoScalingGroupName'],
                ],
                'deploymentStyle' => [
                    'deploymentType' => 'IN_PLACE',
                    'deploymentOption' => Manifest::get('aws.codedeploy.with-load-balancing', false)
                        ? 'WITH_TRAFFIC_CONTROL'
                        : 'WITHOUT_TRAFFIC_CONTROL',
                ],
                'loadBalancerInfo' => [
                    'targetGroupInfoList' => [
                        [
                            'name' => AwsResources::targetGroup()['TargetGroupName'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
