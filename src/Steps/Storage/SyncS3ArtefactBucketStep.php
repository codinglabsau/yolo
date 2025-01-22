<?php

namespace Codinglabs\Yolo\Steps\Storage;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncS3ArtefactBucketStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $bucketName = Paths::s3ArtefactsBucket();

        try {
            AwsResources::bucket($bucketName);
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::s3()->createBucket([
                    'Bucket' => $bucketName,
                ]);

                Aws::s3()->waitUntil('BucketExists', [
                    'Bucket' => $bucketName,
                ]);

                Aws::s3()->putBucketTagging([
                    'Bucket' => $bucketName,
                    'Tagging' => [
                        ...Aws::tags([
                            'Name' => $bucketName,
                        ], wrap: 'TagSet'),
                    ],
                ]);

                // todo: this requires the ELB account ID, which is not the same as the account ID.
                // todo: @see https://docs.aws.amazon.com/elasticloadbalancing/latest/application/enable-access-logging.html
                //                Aws::s3()->putBucketPolicy([
                //                    'Bucket' => $bucketName,
                //                    'Policy' => json_encode([
                //                        "Version" => '2008-10-17',
                //                        'Statement' => [
                //                            'Effect' => 'Allow',
                //                            'Principal' => [
                //                                'AWS' => sprintf("arn:aws:iam::%s:root", 'elb-account-id'),
                //                            ],
                //                            'Action' => 's3:PutObject',
                //                            'Resource' => "arn:aws:s3:::$bucketName/logs/*"
                //                        ],
                //                    ]),
                //                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
