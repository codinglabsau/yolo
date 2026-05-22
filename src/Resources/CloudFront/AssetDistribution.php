<?php

namespace Codinglabs\Yolo\Resources\CloudFront;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\CloudFront;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Storage\AssetBucket;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
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
class AssetDistribution implements Resource, SynchronisesConfiguration
{
    protected const ORIGIN_ID = 'asset-bucket';

    // AWS managed "CachingOptimized" cache policy.
    protected const CACHE_POLICY_ID = '658327ea-f89d-4fab-a63d-7e88639e58f6';

    // AWS managed "SimpleCORS" response-headers policy. Adds a static
    // `Access-Control-Allow-Origin: *` so Vite's crossorigin module +
    // modulepreload fetches resolve when assets come from this domain rather
    // than the app's own origin. Static `*` keeps the cache key origin-agnostic
    // (no Vary: Origin), so it's safe to cache.
    protected const RESPONSE_HEADERS_POLICY_ID = '60669652-455b-4ae9-85a4-c4c02393f86c';

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

    /**
     * Push managed cache-behaviour config onto an existing distribution. Tags
     * alone don't cover config changes (e.g. adding the SimpleCORS policy to a
     * distribution created before it existed), so sync reconciles the behaviour
     * fields we own. CloudFront updates trigger a full edge redeploy (~15 min),
     * so we only call updateDistribution when a managed field has drifted.
     */
    public function synchroniseConfiguration(): void
    {
        $id = CloudFront::distributionByComment($this->name())['Id'];

        $response = Aws::cloudFront()->getDistributionConfig(['Id' => $id]);
        $config = (array) $response['DistributionConfig'];
        $behaviour = (array) $config['DefaultCacheBehavior'];

        $reconciled = array_merge($behaviour, static::reconcilableBehaviour());

        if ($reconciled == $behaviour) {
            return;
        }

        $config['DefaultCacheBehavior'] = $reconciled;

        Aws::cloudFront()->updateDistribution([
            'Id' => $id,
            'DistributionConfig' => $config,
            'IfMatch' => (string) $response['ETag'],
        ]);
    }

    /**
     * The cache-behaviour fields sync keeps current on an existing distribution.
     * Scalars only — the policy IDs are the realistic drift surface; nested
     * blocks like AllowedMethods are set once at create and comparing them risks
     * a false "drift" from key ordering, which would force a needless redeploy.
     *
     * @return array<string, mixed>
     */
    public static function reconcilableBehaviour(): array
    {
        return [
            'ViewerProtocolPolicy' => 'redirect-to-https',
            'Compress' => true,
            'CachePolicyId' => static::CACHE_POLICY_ID,
            'ResponseHeadersPolicyId' => static::RESPONSE_HEADERS_POLICY_ID,
        ];
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
        return [
            'CallerReference' => (string) Str::uuid(),
            'Comment' => $this->name(),
            'Enabled' => true,
            'PriceClass' => 'PriceClass_All',
            'Origins' => [
                'Quantity' => 1,
                'Items' => [
                    [
                        'Id' => static::ORIGIN_ID,
                        // Regional S3 endpoint is required for OAC outside us-east-1.
                        'DomainName' => sprintf('%s.s3.%s.amazonaws.com', $bucket->name(), Manifest::get('aws.region')),
                        'OriginAccessControlId' => $originAccessControlId,
                        'S3OriginConfig' => ['OriginAccessIdentity' => ''],
                    ],
                ],
            ],
            'DefaultCacheBehavior' => [
                'TargetOriginId' => static::ORIGIN_ID,
                ...static::reconcilableBehaviour(),
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
