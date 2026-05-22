<?php

namespace Codinglabs\Yolo\Resources\CloudFront;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\CloudFront;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Storage\AssetBucket;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * CloudFront distribution dedicated to the application's build assets. It has a
 * single S3 origin (the private asset bucket, via Origin Access Control) and
 * default-routes everything to it; it's reached on its own `*.cloudfront.net`
 * domain, which ASSET_URL points at. The app's own DNS stays on the ALB.
 *
 * Assets-only by design. Page caching, if it ever lands, gets its OWN
 * distribution fronting the app — a single distribution path-splitting
 * `builds/*` -> S3 and everything-else -> ALB would hijack any app that has its
 * own `/builds` route. The keyed name is stamped into the distribution's
 * Comment (and the OAC's Name) for lookup, and surfaced as the `Name` tag.
 */
class AssetDistribution implements Resource
{
    public function name(): string
    {
        return Helpers::keyedResourceName('assets', exclusive: true);
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            CloudFront::distributionByComment($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return CloudFront::distributionByComment($this->name())['ARN'];
    }

    public function domain(): string
    {
        return CloudFront::distributionByComment($this->name())['DomainName'];
    }

    public function create(): void
    {
        $bucket = new AssetBucket();
        $oacId = $this->ensureOriginAccessControl();

        $distribution = Aws::cloudFront()->createDistributionWithTags([
            'DistributionConfigWithTags' => [
                'DistributionConfig' => $this->distributionConfig($bucket, $oacId),
                'Tags' => [
                    'Items' => collect(Aws::expectedTags($this->tags()))
                        ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])
                        ->values()
                        ->all(),
                ],
            ],
        ])['Distribution'];

        // Grant the new distribution (and only it) read access to the bucket.
        $this->grantBucketAccess($bucket, $distribution['ARN']);
    }

    public function synchroniseTags(): void
    {
        Aws::cloudFront()->tagResource([
            'Resource' => $this->arn(),
            'Tags' => [
                'Items' => collect(Aws::expectedTags($this->tags()))
                    ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    protected function ensureOriginAccessControl(): string
    {
        try {
            return CloudFront::originAccessControlByName($this->name())['Id'];
        } catch (ResourceDoesNotExistException) {
            return Aws::cloudFront()->createOriginAccessControl([
                'OriginAccessControlConfig' => [
                    'Name' => $this->name(),
                    'OriginAccessControlOriginType' => 's3',
                    'SigningBehavior' => 'always',
                    'SigningProtocol' => 'sigv4',
                ],
            ])['OriginAccessControl']['Id'];
        }
    }

    protected function distributionConfig(AssetBucket $bucket, string $originAccessControlId): array
    {
        $originId = 'asset-bucket';

        return [
            'CallerReference' => (string) Str::uuid(),
            'Comment' => $this->name(),
            'Enabled' => true,
            'PriceClass' => 'PriceClass_All',
            'Origins' => [
                'Quantity' => 1,
                'Items' => [
                    [
                        'Id' => $originId,
                        // Regional S3 endpoint is required for OAC outside us-east-1.
                        'DomainName' => sprintf('%s.s3.%s.amazonaws.com', $bucket->name(), Manifest::get('aws.region')),
                        'OriginAccessControlId' => $originAccessControlId,
                        'S3OriginConfig' => ['OriginAccessIdentity' => ''],
                    ],
                ],
            ],
            'DefaultCacheBehavior' => [
                'TargetOriginId' => $originId,
                'ViewerProtocolPolicy' => 'redirect-to-https',
                'Compress' => true,
                // AWS managed "CachingOptimized" policy.
                'CachePolicyId' => '658327ea-f89d-4fab-a63d-7e88639e58f6',
                'AllowedMethods' => [
                    'Quantity' => 2,
                    'Items' => ['GET', 'HEAD'],
                    'CachedMethods' => [
                        'Quantity' => 2,
                        'Items' => ['GET', 'HEAD'],
                    ],
                ],
            ],
        ];
    }

    protected function grantBucketAccess(AssetBucket $bucket, string $distributionArn): void
    {
        Aws::s3()->putBucketPolicy([
            'Bucket' => $bucket->name(),
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Sid' => 'AllowCloudFrontServicePrincipalReadOnly',
                        'Effect' => 'Allow',
                        'Principal' => ['Service' => 'cloudfront.amazonaws.com'],
                        'Action' => 's3:GetObject',
                        'Resource' => $bucket->arn() . '/*',
                        'Condition' => ['StringEquals' => ['AWS:SourceArn' => $distributionArn]],
                    ],
                ],
            ]),
        ]);
    }
}
