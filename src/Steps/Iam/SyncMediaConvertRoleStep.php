<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncMediaConvertRoleStep implements Step
{
    /**
     * IAM Description character set — see EcsTaskRole::description() comment.
     * Validated by IamDescriptionsAreSafeTest.
     */
    public const DESCRIPTION = 'YOLO managed MediaConvert role';

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('aws.mediaconvert')) {
            return StepResult::SKIPPED;
        }

        try {
            AwsResources::mediaConvertRole();

            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE);

                Aws::iam()->updateRole([
                    'RoleName' => $name,
                    'Description' => static::DESCRIPTION,
                ]);

                Aws::iam()->updateAssumeRolePolicy([
                    'RoleName' => $name,
                    'PolicyDocument' => json_encode(AwsResources::mediaConvertPolicyDocument()),
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
                    'Description' => static::DESCRIPTION,
                    'AssumeRolePolicyDocument' => json_encode(AwsResources::mediaConvertPolicyDocument()),
                    ...Aws::tags(),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
