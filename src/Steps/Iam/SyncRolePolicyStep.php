<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncRolePolicyStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            $policy = AwsResources::ec2Policy();

            $currentPolicyDocument = json_decode(
                urldecode(
                    Aws::iam()->getPolicyVersion([
                        'PolicyArn' => $policy['Arn'],
                        'VersionId' => $policy['DefaultVersionId'],
                    ])['PolicyVersion']['Document']
                ),
                associative: true
            );

            $hasDifferences = Helpers::payloadHasDifferences($currentPolicyDocument, AwsResources::policyDocument());

            if (! Arr::get($options, 'dry-run')) {
                if ($hasDifferences) {
                    Aws::iam()->createPolicyVersion([
                        'PolicyArn' => $policy['Arn'],
                        'PolicyDocument' => json_encode(AwsResources::policyDocument()),
                        'SetAsDefault' => true,
                    ]);

                    return StepResult::SYNCED;
                }

                return StepResult::SYNCED;
            }

            return $hasDifferences
                ? StepResult::OUT_OF_SYNC
                : StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::iam()->createPolicy([
                    'PolicyName' => Helpers::keyedResourceName(exclusive: false),
                    'Description' => 'YOLO managed EC2 policy',
                    'PolicyDocument' => json_encode(AwsResources::policyDocument()),
                    ...Aws::tags(),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
