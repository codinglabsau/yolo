<?php

namespace Codinglabs\Yolo\Resources\CloudFront;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudFront;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\S3\AssetBucket;
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
    use ResolvesTags;

    protected const ORIGIN_ID = 'asset-bucket';

    // Custom cache policy: like CachingOptimized but with `Origin` in the cache
    // key. S3 only returns Access-Control-Allow-Origin when a request carries
    // Origin, so a no-Origin request (CloudFront's own compression fetch, a
    // preload, a bot) would otherwise cache a header-less copy that Origin-
    // bearing browser requests then hit. Keying on Origin caches the with-CORS
    // and without-CORS variants separately, so browsers can't be served a
    // poisoned entry. No managed cache policy includes Origin, hence a custom.
    protected const CACHE_POLICY_NAME = 'yolo-asset-cors';

    // AWS managed "CORS-S3Origin" origin-request policy. Forwards the viewer
    // Origin header (+ Access-Control-Request-*) to S3 so the bucket's own CORS
    // config returns Access-Control-Allow-Origin. CloudFront then caches and
    // serves that as a normal origin header on every path — unlike a
    // response-headers-policy CORS header, which CloudFront silently omits on
    // the revalidation it forces for `Cache-Control: no-cache` / `max-age=0`
    // (reloads, DevTools "Disable cache"). Empty ResponseHeadersPolicyId: the
    // origin now owns CORS, and a second source would emit a duplicate header.
    protected const ORIGIN_REQUEST_POLICY_ID = '88a5eaf4-2fd4-4709-b370-b4c650ea3fcf';

    protected const RESPONSE_HEADERS_POLICY_ID = '';

    public function name(): string
    {
        return $this->keyedName('assets');
    }

    public function scope(): Scope
    {
        return Scope::App;
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
     * alone don't cover config changes (e.g. swapping a distribution to the
     * CORS-S3Origin origin-request policy after it was created), so sync
     * reconciles the behaviour fields we own. CloudFront updates trigger a full
     * edge redeploy (~15 min), so we only call updateDistribution when a managed
     * field has drifted.
     */
    public function synchroniseConfiguration(): void
    {
        $id = CloudFront::distributionByComment($this->name())['Id'];

        $response = Aws::cloudFront()->getDistributionConfig(['Id' => $id]);
        $config = (array) $response['DistributionConfig'];
        $behaviour = (array) $config['DefaultCacheBehavior'];

        $reconciled = array_merge($behaviour, static::reconcilableBehaviour($this->ensureCachePolicy()));

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
    public static function reconcilableBehaviour(string $cachePolicyId): array
    {
        return [
            'ViewerProtocolPolicy' => 'redirect-to-https',
            'Compress' => true,
            'CachePolicyId' => $cachePolicyId,
            'OriginRequestPolicyId' => static::ORIGIN_REQUEST_POLICY_ID,
            'ResponseHeadersPolicyId' => static::RESPONSE_HEADERS_POLICY_ID,
        ];
    }

    /**
     * Resolve the custom cache policy (Origin in the cache key), creating it
     * once if absent. Account-scoped and generic, so every YOLO asset
     * distribution shares the one policy — looked up by name like the OAC.
     */
    protected function ensureCachePolicy(): string
    {
        try {
            return CloudFront::cachePolicyByName(static::CACHE_POLICY_NAME)['Id'];
        } catch (ResourceDoesNotExistException) {
            return Aws::cloudFront()->createCachePolicy([
                'CachePolicyConfig' => [
                    'Name' => static::CACHE_POLICY_NAME,
                    'Comment' => 'YOLO build assets — Origin in cache key for CORS',
                    'MinTTL' => 1,
                    'DefaultTTL' => 86400,
                    'MaxTTL' => 31536000,
                    'ParametersInCacheKeyAndForwardedToOrigin' => [
                        'EnableAcceptEncodingGzip' => true,
                        'EnableAcceptEncodingBrotli' => true,
                        'HeadersConfig' => [
                            'HeaderBehavior' => 'whitelist',
                            'Headers' => ['Quantity' => 1, 'Items' => ['Origin']],
                        ],
                        'CookiesConfig' => ['CookieBehavior' => 'none'],
                        'QueryStringsConfig' => ['QueryStringBehavior' => 'none'],
                    ],
                ],
            ])['CachePolicy']['Id'];
        }
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
                ...static::reconcilableBehaviour($this->ensureCachePolicy()),
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
