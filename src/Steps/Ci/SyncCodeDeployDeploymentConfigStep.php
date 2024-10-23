<?php

namespace Codinglabs\Yolo\Steps\Ci;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncCodeDeployDeploymentConfigStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::OneThirdAtATimeDeploymentConfig();
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::codeDeploy()->createDeploymentConfig([
                    'deploymentConfigName' => 'OneThirdAtATime',
                    'computePlatform' => 'Server',
                    'minimumHealthyHosts' => [
                        'type' => 'FLEET_PERCENT',
                        'value' => 60,
                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
