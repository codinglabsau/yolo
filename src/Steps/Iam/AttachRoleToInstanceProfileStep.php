<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class AttachRoleToInstanceProfileStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $instanceProfile = AwsResources::instanceProfile();
        $attached = ! empty($instanceProfile['Roles']) && $instanceProfile['Roles'][0]['RoleName'] === Helpers::keyedResourceName(exclusive: false);

        if (! Arr::get($options, 'dry-run')) {
            if (! $attached) {
                Aws::iam()->addRoleToInstanceProfile([
                    'InstanceProfileName' => Helpers::keyedResourceName(Iam::INSTANCE_PROFILE, exclusive: false),
                    'RoleName' => Helpers::keyedResourceName(exclusive: false),
                ]);

                return StepResult::SYNCED;
            }

            return StepResult::IN_SYNC;
        }

        return $attached
            ? StepResult::IN_SYNC
            : StepResult::WOULD_SYNC;
    }
}
