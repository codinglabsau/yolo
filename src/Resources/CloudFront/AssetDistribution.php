<?php

namespace Codinglabs\Yolo\Resources\CloudFront;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudFront;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Assets-only distribution: a single OAC S3 origin on its own `*.cloudfront.net`
 * domain (ASSET_URL). Page caching, if it ever lands, gets its OWN distribution —
 * one that path-splits `builds/*` → S3 and the rest → ALB would hijack any app with
 * its own `/builds` route. The keyed name is stamped into the Comment (and the OAC's
 * Name) for lookup.
 *
 * CORS is owned entirely by a response-headers policy stamping a static
 * `Access-Control-Allow-Origin: *`; the cache key carries no request headers, so
 * there is one cache entry per object and no Vary: Origin split. See the constants.
 */
class AssetDistribution implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    protected const ORIGIN_ID = 'asset-bucket';

    // AWS managed "CachingOptimized": no request headers in the cache key, so one
    // entry per object regardless of viewer Origin. Keying on Origin split the cache
    // into with-/without-CORS variants; a transient 503 cached against the sparsely
    // warmed one broke every cross-origin import() until the next deploy.
    protected const CACHE_POLICY_ID = '658327ea-f89d-4fab-a63d-7e88639e58f6';

    // No origin-request policy: Origin is never forwarded, so S3 never emits its own
    // CORS headers — the response-headers policy is the single source.
    protected const ORIGIN_REQUEST_POLICY_ID = '';

    // AWS rejects ACAO/ACAM as CustomHeadersConfig entries ("cannot be set as custom
    // header") — they belong in CorsConfig, with OriginOverride so the policy wins
    // over any origin CORS header. Account-scoped and generic: every asset
    // distribution shares it, looked up by name like the OAC.
    protected const RESPONSE_HEADERS_POLICY_NAME = 'yolo-asset-headers';

    // Every object under `builds/{version}/` is immutable, so a cached transient
    // 5xx would never self-bust.
    protected const ERROR_CODES_NOT_CACHED = [500, 502, 503, 504];

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
        $responseHeadersPolicyId = $this->ensureResponseHeadersPolicy();

        $distribution = Aws::cloudFront()->createDistributionWithTags([
            'DistributionConfigWithTags' => [
                'DistributionConfig' => $this->distributionConfig($oacId, $responseHeadersPolicyId),
                'Tags' => [
                    'Items' => collect(Aws::expectedTags($this->tags()))
                        ->map(fn ($value, $key): array => ['Key' => $key, 'Value' => $value])
                        ->values()
                        ->all(),
                ],
            ],
        ])['Distribution'];

        $this->grantBucketAccess($bucket, $distribution['ARN']);

        // Enable at create — left to synchroniseConfiguration() every first sync
        // would self-drift and trip the next deploy's drift gate.
        CloudFront::enableAdditionalMetrics($distribution['Id']);
    }

    /**
     * CloudFront refuses to delete an enabled distribution, and the disable is a
     * ~15 min edge redeploy: disable only if still enabled (so a retry after a
     * half-done teardown skips to the wait), wait, then delete with a fresh ETag.
     */
    public function delete(): void
    {
        try {
            $id = CloudFront::distributionByComment($this->name())['Id'];
        } catch (ResourceDoesNotExistException) {
            return;
        }

        $response = Aws::cloudFront()->getDistributionConfig(['Id' => $id]);
        $config = (array) $response['DistributionConfig'];

        if ($config['Enabled'] ?? false) {
            $config['Enabled'] = false;

            Aws::cloudFront()->updateDistribution([
                'Id' => $id,
                'DistributionConfig' => $config,
                'IfMatch' => (string) $response['ETag'],
            ]);
        }

        // Full propagation routinely runs 15+ min; the 600s default would abort a routine teardown.
        Aws::waitFor(Aws::cloudFront(), 'DistributionDeployed', ['Id' => $id], timeout: 1800);

        Aws::cloudFront()->deleteDistribution([
            'Id' => $id,
            'IfMatch' => (string) Aws::cloudFront()->getDistributionConfig(['Id' => $id])['ETag'],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseCloudFrontTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Reconciles the origin (so a renamed asset bucket converges) and the OAC bucket
     * grant (so a policy deleted out-of-band self-heals instead of 403ing every asset
     * while the plan reads clean) alongside the cache-behaviour fields. The grant is
     * written before the distribution update so the origin is readable when an edge
     * flips. An update is a ~15 min edge redeploy, so updateDistribution fires only
     * on distribution-field drift. A dry-run never creates the response-headers policy.
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $bucket = new AssetBucket();
        $distribution = CloudFront::distributionByComment($this->name());

        $response = Aws::cloudFront()->getDistributionConfig(['Id' => $distribution['Id']]);
        $config = (array) $response['DistributionConfig'];
        $behaviour = (array) $config['DefaultCacheBehavior'];

        $desired = static::reconcilableBehaviour($this->resolveResponseHeadersPolicyId($apply));

        $distributionChanges = static::behaviourDrift($behaviour, $desired);

        if (($errorChange = static::errorCachingDrift((array) ($config['CustomErrorResponses'] ?? []))) instanceof Change) {
            $distributionChanges[] = $errorChange;
        }

        if (($originChange = static::originDrift((array) ($config['Origins'] ?? []))) instanceof Change) {
            $distributionChanges[] = $originChange;
        }

        $policyChange = $this->bucketPolicyDrift($bucket, $distribution['ARN']);

        // Off by default; the dashboard's CDN cache-hit panel charts them.
        $metricsChange = CloudFront::additionalMetricsEnabled($distribution['Id'])
            ? null
            : Change::make('cdn-additional-metrics', 'disabled', 'enabled');

        $changes = [
            ...$distributionChanges,
            ...$policyChange instanceof Change ? [$policyChange] : [],
            ...$metricsChange instanceof Change ? [$metricsChange] : [],
        ];

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        if ($policyChange instanceof Change) {
            $this->grantBucketAccess($bucket, $distribution['ARN']);
        }

        if ($metricsChange instanceof Change) {
            CloudFront::enableAdditionalMetrics($distribution['Id']);
        }

        if ($distributionChanges !== []) {
            $config['DefaultCacheBehavior'] = array_merge($behaviour, $desired);
            $config['CustomErrorResponses'] = static::customErrorResponses();
            $config['Origins']['Items'][0]['DomainName'] = static::desiredOriginDomain();

            Aws::cloudFront()->updateDistribution([
                'Id' => $distribution['Id'],
                'DistributionConfig' => $config,
                'IfMatch' => (string) $response['ETag'],
            ]);
        }

        return $changes;
    }

    protected function bucketPolicyDrift(AssetBucket $bucket, string $distributionArn): ?Change
    {
        $current = S3::bucketPolicy($bucket->name());
        $desired = static::oacReadPolicy($bucket, $distributionArn);

        return Helpers::documentsEqual($current, $desired)
            ? null
            : Change::make('asset-bucket-policy', $current === null ? null : 'present', 'cloudfront-oac-read');
    }

    public static function originDrift(array $origins): ?Change
    {
        $current = (string) ($origins['Items'][0]['DomainName'] ?? '');
        $desired = static::desiredOriginDomain();

        return $current === $desired
            ? null
            : Change::make('origin', $current, $desired);
    }

    /** The regional endpoint is required for OAC outside us-east-1. */
    public static function desiredOriginDomain(): string
    {
        return sprintf('%s.s3.%s.amazonaws.com', (new AssetBucket())->name(), Manifest::get('region'));
    }

    /**
     * Scalars only — nested blocks like AllowedMethods risk false drift from key
     * ordering, which would force a needless redeploy.
     *
     * @return array<string, mixed>
     */
    public static function reconcilableBehaviour(string $responseHeadersPolicyId): array
    {
        return [
            'ViewerProtocolPolicy' => 'redirect-to-https',
            'Compress' => true,
            'CachePolicyId' => static::CACHE_POLICY_ID,
            'OriginRequestPolicyId' => static::ORIGIN_REQUEST_POLICY_ID,
            'ResponseHeadersPolicyId' => $responseHeadersPolicyId,
        ];
    }

    /**
     * `ResponsePagePath` and `ResponseCode` must be sent as empty strings even with
     * no custom error page — AWS rejects UpdateDistribution ("custom error responses
     * does not exist or is not valid") if they're omitted.
     *
     * @return array{Quantity: int, Items: array<int, array{ErrorCode: int, ResponsePagePath: string, ResponseCode: string, ErrorCachingMinTTL: int}>}
     */
    public static function customErrorResponses(): array
    {
        return [
            'Quantity' => count(static::ERROR_CODES_NOT_CACHED),
            'Items' => array_map(fn (int $code): array => [
                'ErrorCode' => $code,
                'ResponsePagePath' => '',
                'ResponseCode' => '',
                'ErrorCachingMinTTL' => 0,
            ], static::ERROR_CODES_NOT_CACHED),
        ];
    }

    /**
     * Absent live == desired '': CloudFront accepts '' for OriginRequestPolicyId on
     * write but returns it absent on read, which would otherwise read as permanent drift.
     *
     * @param  array<string, mixed>  $behaviour
     * @param  array<string, mixed>  $desired
     * @return array<int, Change>
     */
    public static function behaviourDrift(array $behaviour, array $desired): array
    {
        return collect($desired)
            ->filter(fn (mixed $value, string $key): bool => ($behaviour[$key] ?? '') !== $value)
            ->map(fn (mixed $value, string $key): Change => Change::make($key, $behaviour[$key] ?? null, $value))
            ->values()
            ->all();
    }

    /**
     * Compared by code → TTL only, so AWS's default fields and item ordering can't
     * read as drift and force a needless redeploy.
     */
    public static function errorCachingDrift(array $live): ?Change
    {
        $cached = collect($live['Items'] ?? [])
            ->mapWithKeys(fn (array $item): array => [(int) $item['ErrorCode'] => (int) $item['ErrorCachingMinTTL']]);

        $pinned = collect(static::ERROR_CODES_NOT_CACHED)
            ->every(fn (int $code): bool => $cached->get($code) === 0);

        if ($pinned) {
            return null;
        }

        return new Change(
            'CustomErrorResponses',
            $cached->isEmpty() ? 'unset (CloudFront default ~10s)' : 'caches some 5xx',
            sprintf('TTL 0 for %s', collect(static::ERROR_CODES_NOT_CACHED)->implode('/')),
        );
    }

    /** Never creates the policy as a side effect of a read-only plan. */
    protected function resolveResponseHeadersPolicyId(bool $apply): string
    {
        try {
            return CloudFront::responseHeadersPolicyByName(static::RESPONSE_HEADERS_POLICY_NAME)['Id'];
        } catch (ResourceDoesNotExistException) {
            return $apply ? $this->ensureResponseHeadersPolicy() : sprintf('(pending: %s)', static::RESPONSE_HEADERS_POLICY_NAME);
        }
    }

    protected function ensureResponseHeadersPolicy(): string
    {
        try {
            return CloudFront::responseHeadersPolicyByName(static::RESPONSE_HEADERS_POLICY_NAME)['Id'];
        } catch (ResourceDoesNotExistException) {
            return Aws::cloudFront()->createResponseHeadersPolicy([
                'ResponseHeadersPolicyConfig' => [
                    'Name' => static::RESPONSE_HEADERS_POLICY_NAME,
                    'Comment' => 'YOLO build assets — static Access-Control-Allow-Origin: * on every response',
                    'CorsConfig' => [
                        // Assets are public-by-design (immutable, content-hashed), so
                        // there's no caller to discriminate against.
                        'AccessControlAllowOrigins' => [
                            'Quantity' => 1,
                            'Items' => ['*'],
                        ],
                        'AccessControlAllowMethods' => [
                            'Quantity' => 3,
                            'Items' => ['GET', 'HEAD', 'OPTIONS'],
                        ],
                        'AccessControlAllowHeaders' => [
                            'Quantity' => 1,
                            'Items' => ['*'],
                        ],
                        // AWS rejects ACAO * combined with credentials.
                        'AccessControlAllowCredentials' => false,
                        // Policy values win even if S3 ever surfaces its own CORS header.
                        'OriginOverride' => true,
                    ],
                ],
            ])['ResponseHeadersPolicy']['Id'];
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

    protected function distributionConfig(string $originAccessControlId, string $responseHeadersPolicyId): array
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
                        'DomainName' => static::desiredOriginDomain(),
                        'OriginAccessControlId' => $originAccessControlId,
                        'S3OriginConfig' => ['OriginAccessIdentity' => ''],
                    ],
                ],
            ],
            'DefaultCacheBehavior' => [
                'TargetOriginId' => static::ORIGIN_ID,
                ...static::reconcilableBehaviour($responseHeadersPolicyId),
                'AllowedMethods' => [
                    'Quantity' => 2,
                    'Items' => ['GET', 'HEAD'],
                    'CachedMethods' => [
                        'Quantity' => 2,
                        'Items' => ['GET', 'HEAD'],
                    ],
                ],
            ],
            'CustomErrorResponses' => static::customErrorResponses(),
        ];
    }

    protected function grantBucketAccess(AssetBucket $bucket, string $distributionArn): void
    {
        Aws::s3()->putBucketPolicy([
            'Bucket' => $bucket->name(),
            'Policy' => json_encode(static::oacReadPolicy($bucket, $distributionArn)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function oacReadPolicy(AssetBucket $bucket, string $distributionArn): array
    {
        return [
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
        ];
    }
}
