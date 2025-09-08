<?php

namespace Codinglabs\Yolo\Steps\Compute;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncLaunchTemplateStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            // ensure the launch template exists; refer to "yolo image:create"
            // to create new launch template versions with synced attributes.
            AwsResources::launchTemplate();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::ec2()->createLaunchTemplate(
                    AwsResources::launchTemplatePayload(),
                );

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
