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

class SyncEcsTaskPolicyStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $document = json_encode(AwsResources::ecsTaskPolicyDocument());

        try {
            $policy = AwsResources::ecsTaskPolicy();

            $currentVersion = Aws::iam()->getPolicyVersion([
                'PolicyArn' => $policy['Arn'],
                'VersionId' => $policy['DefaultVersionId'],
            ])['PolicyVersion'];

            if (urldecode($currentVersion['Document']) === $document) {
                return StepResult::SYNCED;
            }

            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            Aws::iam()->createPolicyVersion([
                'PolicyArn' => $policy['Arn'],
                'PolicyDocument' => $document,
                'SetAsDefault' => true,
            ]);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::iam()->createPolicy([
                'PolicyName' => Helpers::keyedResourceName(Iam::ECS_TASK_POLICY, exclusive: false),
                'Description' => 'YOLO managed policy granting ECS Exec session channel permissions to the shared task role',
                'PolicyDocument' => $document,
                ...Aws::tags(),
            ]);

            return StepResult::CREATED;
        }
    }
}
