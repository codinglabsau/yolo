<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class AttachEc2RoleToInstanceProfileStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            $instanceProfile = AwsResources::ec2InstanceProfile();
            $attached = ! empty($instanceProfile['Roles']) && $instanceProfile['Roles'][0]['RoleName'] === Helpers::keyedResourceName(exclusive: false);

            if (! Arr::get($options, 'dry-run')) {
                if (! $attached) {
                    Aws::iam()->addRoleToInstanceProfile([
                        'InstanceProfileName' => $instanceProfile['InstanceProfileName'],
                        'RoleName' => Helpers::keyedResourceName(exclusive: false),
                    ]);

                    return StepResult::SYNCED;
                }

                return StepResult::SYNCED;
            }

            return $attached
                ? StepResult::SYNCED
                : StepResult::WOULD_SYNC;
        } catch (ResourceDoesNotExistException $e) {
            return StepResult::WOULD_SYNC;
        }
    }
}
