<?php

namespace Codinglabs\Yolo\Resources\Storage;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Private, app-exclusive bucket holding the application's `.env` files and build
 * artefacts. Because it stores secrets it must never be publicly reachable and
 * its objects must be recoverable, so Block Public Access (all four settings)
 * and versioning are reconciled onto it on every sync — both declarative,
 * idempotent puts. The yolo:app owner tag lets `yolo audit` attribute it.
 *
 * It also doubles as the destination for the ALB's access logs, so sync grants
 * the ELB log-delivery service principal write access under the alb-access-logs/
 * prefix.
 */
class S3ArtefactBucket implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return Paths::s3ArtefactsBucket();
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        return S3::bucketExists($this->name());
    }

    public function arn(): string
    {
        return 'arn:aws:s3:::' . $this->name();
    }

    public function create(): void
    {
        Aws::s3()->createBucket([
            'Bucket' => $this->name(),
        ]);

        Aws::s3()->waitUntil('BucketExists', [
            'Bucket' => $this->name(),
        ]);

        $this->synchroniseTags();
        $this->synchroniseConfiguration();
    }

    public function synchroniseTags(): void
    {
        Aws::s3()->putBucketTagging([
            'Bucket' => $this->name(),
            'Tagging' => Aws::tags($this->tags(), wrap: 'TagSet'),
        ]);
    }

    public function synchroniseConfiguration(): void
    {
        Aws::s3()->putPublicAccessBlock([
            'Bucket' => $this->name(),
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true,
                'IgnorePublicAcls' => true,
                'BlockPublicPolicy' => true,
                'RestrictPublicBuckets' => true,
            ],
        ]);

        Aws::s3()->putBucketVersioning([
            'Bucket' => $this->name(),
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);

        $this->grantAlbAccessLogDelivery();
    }

    /**
     * Grant Elastic Load Balancing permission to deliver ALB access logs into this
     * bucket under the alb-access-logs/ prefix. Enabling access logs on the load
     * balancer (LoadBalancer resource) fails AWS's write-test without this, so the
     * policy must exist before sync:compute runs — Storage runs ahead of Compute,
     * so it does.
     *
     * Uses the log-delivery service principal (recommended for the SSE-S3-encrypted
     * artefacts bucket; no customer-managed KMS key in play) rather than a
     * per-Region ELB account ID. SourceAccount + SourceArn scope the grant to this
     * account's load balancers, so it is never public and coexists with the
     * bucket's BlockPublicPolicy. putBucketPolicy is a full replace and the
     * artefacts bucket carries no other policy, so this is idempotent on re-sync.
     */
    protected function grantAlbAccessLogDelivery(): void
    {
        $accountId = Aws::accountId();

        Aws::s3()->putBucketPolicy([
            'Bucket' => $this->name(),
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Sid' => 'AllowELBAccessLogDelivery',
                        'Effect' => 'Allow',
                        'Principal' => ['Service' => 'logdelivery.elasticloadbalancing.amazonaws.com'],
                        'Action' => 's3:PutObject',
                        'Resource' => sprintf('arn:aws:s3:::%s/alb-access-logs/*', $this->name()),
                        'Condition' => [
                            'StringEquals' => ['aws:SourceAccount' => $accountId],
                            'ArnLike' => [
                                'aws:SourceArn' => sprintf(
                                    'arn:aws:elasticloadbalancing:%s:%s:loadbalancer/*',
                                    Manifest::get('aws.region'),
                                    $accountId,
                                ),
                            ],
                        ],
                    ],
                ],
            ]),
        ]);
    }
}
