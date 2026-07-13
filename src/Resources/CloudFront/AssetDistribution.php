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
 *
 * CORS for cross-origin module imports (the app is on the ALB domain, assets on
 * this distribution) is owned entirely by a response-headers policy that stamps
 * a static `Access-Control-Allow-Origin: *` on every response. The cache key
 * carries no request headers, so there is one cache entry per object for every
 * viewer — no Vary: Origin split, and a transient origin 5xx is never cached
 * (ErrorCachingMinTTL 0) against the immutable, content-hashed paths it would
 * otherwise poison until the next deploy. See the constants below.
 */
class AssetDistribution implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    protected const ORIGIN_ID = 'asset-bucket';

    // AWS managed "CachingOptimized". No request headers in the cache key (gzip
    // /brotli only), so there is a single cache entry per object regardless of
    // the viewer's Origin. The previous custom "Origin in the key" policy split
    // the cache into with-CORS / without-CORS variants; the browser-only variant
    // was sparsely warmed and a transient origin 503 cached against it broke
    // every cross-origin import() until the next deploy. One entry can't drift
    // apart like that. Same TTLs as the old custom policy (1 / 86400 / 31536000).
    protected const CACHE_POLICY_ID = '658327ea-f89d-4fab-a63d-7e88639e58f6';

    // No origin-request policy: the viewer Origin header is no longer forwarded
    // to S3, so S3 never runs its bucket CORS and never emits its own
    // Access-Control-Allow-Origin. CORS is owned solely by the response-headers
    // policy below — one unconditional header, no second source to double up,
    // and nothing Origin-dependent reaching the origin to force a Vary.
    protected const ORIGIN_REQUEST_POLICY_ID = '';

    // Custom response-headers policy that stamps a static
    // `Access-Control-Allow-Origin: *` on every response, via the policy's
    // dedicated CorsConfig with OriginOverride: true. AWS rejects ACAO/ACAM/etc.
    // as CustomHeadersConfig entries ("cannot be set as custom header") — those
    // headers belong in CorsConfig, where CloudFront documents the policy's
    // headers as "added to every response that CloudFront sends" for the
    // matched cache behaviour. OriginOverride: true means the policy values
    // win even when the origin's response includes its own CORS headers, so
    // there's no Origin-dependent variance reaching the cached object.
    // Account-scoped and generic, so every YOLO asset distribution shares the
    // one policy — looked up by name like the OAC.
    protected const RESPONSE_HEADERS_POLICY_NAME = 'yolo-asset-headers';

    // Build assets live under a per-deploy `builds/{version}/` prefix (ASSET_URL
    // carries the version), so every object is immutable — a new deploy is a new
    // URL, never an overwrite. A transient origin 5xx must therefore never be
    // cached against these paths: the poisoned entry would never self-bust.
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

        // Grant the new distribution (and only it) read access to the bucket.
        $this->grantBucketAccess($bucket, $distribution['ARN']);

        // Additional metrics are part of the desired state (the dashboard's CDN
        // cache-hit panel charts them), so enable them at create — leaving it to
        // synchroniseConfiguration() would make every first sync self-drift and
        // trip the next deploy's drift gate.
        CloudFront::enableAdditionalMetrics($distribution['Id']);
    }

    /**
     * Tear the distribution down. CloudFront refuses to delete an enabled
     * distribution, and the disable is an edge redeploy (~15 min) that must
     * finish before the delete — so: disable (only if still enabled, so a retry
     * after a half-done teardown skips straight to the wait), block on the
     * DistributionDeployed waiter, then delete with a freshly-read ETag. The
     * asset bucket's OAC read policy dies with the bucket (a separate App
     * resource), so nothing else to unwind here. Already gone ⇒ no-op.
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

        // The disable must reach every edge (Deployed) before CloudFront will
        // delete it — a full propagation routinely runs 15+ minutes, so the
        // waiter timeout is raised well past the 600s default to match (a too-low
        // timeout would throw and abort the teardown on a routine slow deploy).
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
     * Push managed config onto an existing distribution. Tags alone don't cover
     * config changes, so sync reconciles the fields we own: the cache-behaviour
     * policy IDs (swapping a distribution off the old Origin-keyed cache policy
     * onto CachingOptimized + the static-CORS response-headers policy), the
     * 5xx error-caching rules, the S3 origin — so a renamed asset bucket
     * converges through sync instead of leaving the distribution serving from
     * the orphaned old one — and the OAC read grant on the asset bucket,
     * reconciled declaratively so a policy deleted out-of-band (or a sync that
     * died between the policy write and the distribution update) self-heals on
     * the next run instead of 403ing every asset while the plan reads clean.
     * The grant is written *before* the distribution update so the origin is
     * readable by the time any edge flips. CloudFront updates trigger a full
     * edge redeploy (~15 min), so updateDistribution only fires when a
     * distribution field has drifted, and the drifted fields are returned so
     * sync can report each current → desired comparison. A dry-run ($apply
     * false) never creates the response-headers policy and never writes.
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

        // Additional CloudWatch metrics (cache hit rate, origin latency, error
        // rate by status) are off by default and cost cents/month — YOLO always
        // turns them on so the dashboard's CDN cache-hit panel has data to chart.
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

    /**
     * Drift check for the asset bucket's OAC read grant — the bucket policy
     * must be exactly the single read statement for this distribution.
     */
    protected function bucketPolicyDrift(AssetBucket $bucket, string $distributionArn): ?Change
    {
        $current = S3::bucketPolicy($bucket->name());
        $desired = static::oacReadPolicy($bucket, $distributionArn);

        return Helpers::documentsEqual($current, $desired)
            ? null
            : Change::make('asset-bucket-policy', $current === null ? null : 'present', 'cloudfront-oac-read');
    }

    /**
     * The origin must point at the current asset bucket's regional endpoint;
     * anything else (e.g. a distribution still on a pre-rename bucket name)
     * is drift.
     */
    public static function originDrift(array $origins): ?Change
    {
        $current = (string) ($origins['Items'][0]['DomainName'] ?? '');
        $desired = static::desiredOriginDomain();

        return $current === $desired
            ? null
            : Change::make('origin', $current, $desired);
    }

    /**
     * The regional S3 endpoint (required for OAC outside us-east-1) of the
     * asset bucket.
     */
    public static function desiredOriginDomain(): string
    {
        return sprintf('%s.s3.%s.amazonaws.com', (new AssetBucket())->name(), Manifest::get('region'));
    }

    /**
     * The cache-behaviour fields sync keeps current on an existing distribution.
     * Scalars only — the policy IDs are the realistic drift surface; nested
     * blocks like AllowedMethods are set once at create and comparing them risks
     * a false "drift" from key ordering, which would force a needless redeploy.
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
     * The 5xx error-caching rules. ErrorCachingMinTTL 0 means a transient origin
     * blip is never cached, so the next request retries the origin and self-heals
     * rather than pinning a broken entry against an immutable asset path.
     *
     * `ResponsePagePath` and `ResponseCode` must be sent as empty strings even
     * when we don't want a custom error page — AWS rejects UpdateDistribution
     * with "The specified list of custom error responses does not exist or is
     * not valid" if they're omitted. CloudFront stores them as `''` in the
     * default-no-custom-page case, matching what DescribeDistribution returns.
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
     * Drifted reconcilable-behaviour fields between live and desired. Treats an
     * absent live value as equivalent to a desired empty string — CloudFront
     * returns an unset OriginRequestPolicyId as absent on read but accepts ''
     * on UpdateDistribution write, so the value `'' → absent` round-trips and
     * would otherwise read as permanent drift on every plan after apply.
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
     * Whether the live CustomErrorResponses already pin every tracked 5xx to a
     * 0s cache TTL. Compared by code → TTL only (not the whole block) so AWS's
     * default ResponseCode/ResponsePagePath fields and item ordering can't read
     * as false drift and force a needless redeploy. Returns null when already in
     * sync, otherwise a Change with a semantic label rather than a JSON dump.
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

    /**
     * The response-headers policy id for diffing. An existing distribution was
     * created with the policy already in place, so the lookup all but always
     * resolves; the dry-run fallback ('(pending …)') only shows if it were
     * somehow missing, and never creates the policy as a side effect of a
     * read-only --dry-run.
     */
    protected function resolveResponseHeadersPolicyId(bool $apply): string
    {
        try {
            return CloudFront::responseHeadersPolicyByName(static::RESPONSE_HEADERS_POLICY_NAME)['Id'];
        } catch (ResourceDoesNotExistException) {
            return $apply ? $this->ensureResponseHeadersPolicy() : sprintf('(pending: %s)', static::RESPONSE_HEADERS_POLICY_NAME);
        }
    }

    /**
     * Resolve the custom response-headers policy (static ACAO), creating it once
     * if absent. Account-scoped and generic, so every YOLO asset distribution
     * shares the one policy — looked up by name like the OAC.
     */
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
                        // `*` matches every cross-origin caller — the asset bucket
                        // is public-by-design (immutable, content-hashed build
                        // artefacts) so there's no caller to discriminate against.
                        'AccessControlAllowOrigins' => [
                            'Quantity' => 1,
                            'Items' => ['*'],
                        ],
                        // Browsers only fetch static assets with GET/HEAD plus the
                        // OPTIONS preflight; anything else against the asset
                        // distribution would already be wrong.
                        'AccessControlAllowMethods' => [
                            'Quantity' => 3,
                            'Items' => ['GET', 'HEAD', 'OPTIONS'],
                        ],
                        'AccessControlAllowHeaders' => [
                            'Quantity' => 1,
                            'Items' => ['*'],
                        ],
                        // ACAO: * is incompatible with credentials, so this MUST
                        // be false — AWS would reject the combination otherwise.
                        'AccessControlAllowCredentials' => false,
                        // The asset bucket no longer sees Origin (no origin-request
                        // policy), so it never sends its own CORS headers — but
                        // setting OriginOverride: true makes the policy's values
                        // win regardless, even if S3 ever surfaces one.
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
     * Grant the distribution (and only it) read access to the bucket — the
     * OAC service principal scoped to this distribution's ARN.
     *
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
