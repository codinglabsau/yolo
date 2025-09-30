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

class SyncMediaConvertRoleStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::mediaConvertRole();

            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE);

                Aws::iam()->updateRole([
                    'RoleName' => $name,
                    'Description' => 'YOLO managed MediaConvert role',
                ]);

                Aws::iam()->updateAssumeRolePolicy([
                    'RoleName' => $name,
                    'PolicyDocument' => json_encode(AwsResources::mediaConvertS3PolicyDocument()),
                ]);

                Aws::iam()->tagRole([
                    'RoleName' => $name,
                    ...Aws::tags(),
                ]);

                return StepResult::SYNCED;
            }

            return StepResult::WOULD_SYNC;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::iam()->createRole([
                    'RoleName' => Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE),
                    'Description' => 'YOLO managed MediaConvert role',
                    'AssumeRolePolicyDocument' => json_encode(AwsResources::mediaConvertS3PolicyDocument()),
                    ...Aws::tags(),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
