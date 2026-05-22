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
        $dryRun = (bool) Arr::get($options, 'dry-run');

        try {
            AwsResources::bucket($bucketName);

            // Reconcile the hardening onto the existing bucket — safe to re-apply
            // (it's never public and tiny), so older buckets pick it up on sync.
            if (! $dryRun) {
                $this->harden($bucketName);
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! $dryRun) {
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

                $this->harden($bucketName);

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

    /**
     * The artefact bucket holds the application's `.env` files, so it must never
     * be publicly reachable and its objects should be recoverable. Lock down all
     * four Block Public Access settings and enable versioning (recovery +
     * tamper-evidence for a clobbered or malicious `.env`). Both are declarative
     * puts — idempotent, safe to re-apply on every sync.
     */
    protected function harden(string $bucketName): void
    {
        Aws::s3()->putPublicAccessBlock([
            'Bucket' => $bucketName,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true,
                'IgnorePublicAcls' => true,
                'BlockPublicPolicy' => true,
                'RestrictPublicBuckets' => true,
            ],
        ]);

        Aws::s3()->putBucketVersioning([
            'Bucket' => $bucketName,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
    }
}
