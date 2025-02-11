<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class AttachRoleToInstanceProfileStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            Aws::iam()->addRoleToInstanceProfile([
                'InstanceProfileName' => Helpers::keyedResourceName(exclusive: false),
                'RoleName' => Helpers::keyedResourceName(exclusive: false),
            ]);

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
