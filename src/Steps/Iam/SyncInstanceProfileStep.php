<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncInstanceProfileStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $name = Helpers::keyedResourceName(Iam::INSTANCE_PROFILE, exclusive: false);

        try {
            AwsResources::instanceProfile();

            if (! Arr::get($options, 'dry-run')) {
                Aws::iam()->tagInstanceProfile([
                    'InstanceProfileName' => $name,
                    ...Aws::tags(),
                ]);

                return StepResult::SYNCED;
            }

            return StepResult::WOULD_SYNC;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::iam()->createInstanceProfile([
                    'InstanceProfileName' => $name,
                    ...Aws::tags(),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
