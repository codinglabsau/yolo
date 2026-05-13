<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Recording\SyncIvsRealtimeRecordingBucketStep;

class AttachLambdaIvsRemuxRolePoliciesStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsRealtimeWebhookUrl()) {
            return StepResult::SKIPPED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $role = AwsResources::lambdaIvsRemuxRole();
            $realtimeBucket = SyncIvsRealtimeRecordingBucketStep::bucketName();
            $mainBucket = Manifest::ivsRealtimeMainBucket();

            Aws::iam()->putRolePolicy([
                'RoleName' => $role['RoleName'],
                'PolicyName' => 'LambdaIvsRemuxPolicy',
                'PolicyDocument' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [
                        [
                            'Effect' => 'Allow',
                            'Action' => ['logs:CreateLogGroup', 'logs:CreateLogStream', 'logs:PutLogEvents'],
                            'Resource' => '*',
                        ],
                        [
                            'Effect' => 'Allow',
                            'Action' => ['s3:GetObject', 's3:ListBucket'],
                            'Resource' => [
                                "arn:aws:s3:::{$realtimeBucket}",
                                "arn:aws:s3:::{$realtimeBucket}/*",
                            ],
                        ],
                        [
                            'Effect' => 'Allow',
                            'Action' => ['s3:PutObject'],
                            'Resource' => "arn:aws:s3:::{$mainBucket}/tmp/realtime-mp4/*",
                        ],
                        [
                            'Effect' => 'Allow',
                            'Action' => ['ivs:GetStage'],
                            'Resource' => '*',
                        ],
                    ],
                ]),
            ]);

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
