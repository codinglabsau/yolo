<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Aws\CloudFront\CloudFrontClient;
use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;

/**
 * Bind a CloudFront client that records every call and returns the response
 * mapped to its command name (missing entries default to a benign empty
 * Result). Returns a recorder whose `$calls` is `[['name', 'args'], ...]`.
 *
 * @param  array<string, Result>  $byCommand
 */
function bindRecordingCloudFrontClient(array $byCommand): object
{
    $recorder = new class($byCommand) extends MockHandler
    {
        /** @var array<int, array{name: string, args: array<string, mixed>}> */
        public array $calls = [];

        public function __construct(public array $byCommand) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            return Create::promiseFor($this->byCommand[$cmd->getName()] ?? new Result());
        }
    };

    Helpers::app()->instance('cloudFront', new CloudFrontClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $recorder,
    ]));

    return $recorder;
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('enables additional metrics at create so the first sync converges', function (): void {
    // The monitoring subscription is desired state, and it must land in create()
    // — enabling it only in synchroniseConfiguration() makes every fresh
    // distribution self-drift (created without metrics, flagged as drift on the
    // very next plan), which trips the deploy gate right after a first sync.
    $s3 = [];
    bindRoutedS3Client(['PutBucketPolicy' => new Result()], $s3);

    $cloudFront = bindRecordingCloudFrontClient([
        'CreateOriginAccessControl' => new Result(['OriginAccessControl' => ['Id' => 'oac-1']]),
        'CreateResponseHeadersPolicy' => new Result(['ResponseHeadersPolicy' => ['Id' => 'rhp-1']]),
        'CreateDistributionWithTags' => new Result(['Distribution' => [
            'Id' => 'DIST123',
            'ARN' => 'arn:aws:cloudfront::111111111111:distribution/DIST123',
        ]]),
    ]);

    (new AssetDistribution())->create();

    $subscription = collect($cloudFront->calls)->firstWhere('name', 'CreateMonitoringSubscription');

    expect($subscription)->not->toBeNull()
        ->and($subscription['args']['DistributionId'])->toBe('DIST123')
        ->and($subscription['args']['MonitoringSubscription']['RealtimeMetricsSubscriptionConfig']['RealtimeMetricsSubscriptionStatus'])->toBe('Enabled');
});

it('names + tags per app + environment', function (): void {
    expect((new AssetDistribution())->name())->toBe('yolo-testing-my-app-assets');
    expect((new AssetDistribution())->tags())->toBe(['Name' => 'yolo-testing-my-app-assets', 'yolo:scope' => 'app', 'yolo:app' => 'my-app']);
});

it('resolves its domain from the distribution matching its comment', function (): void {
    bindMockCloudFrontClient([
        [
            'Comment' => 'yolo-testing-my-app-assets',
            'DomainName' => 'd123abc.cloudfront.net',
            'ARN' => 'arn:aws:cloudfront::111111111111:distribution/E123',
            'Id' => 'E123',
        ],
    ]);

    expect((new AssetDistribution())->exists())->toBeTrue();
    expect((new AssetDistribution())->domain())->toBe('d123abc.cloudfront.net');
    expect((new AssetDistribution())->arn())->toBe('arn:aws:cloudfront::111111111111:distribution/E123');
});

it('reports not-exists when no distribution matches its comment', function (): void {
    bindMockCloudFrontClient([
        ['Comment' => 'yolo-testing-other-app-assets', 'DomainName' => 'd999.cloudfront.net', 'ARN' => 'arn:...', 'Id' => 'E999'],
    ]);

    expect((new AssetDistribution())->exists())->toBeFalse();
});

it('reconciles the managed cache-behaviour policy fields', function (): void {
    $behaviour = AssetDistribution::reconcilableBehaviour('rhp-resolved-id');

    // CORS is served by a static Access-Control-Allow-Origin: * on the
    // response-headers policy, so no Origin reaches the origin and no Origin is
    // in the cache key — managed CachingOptimized, no origin-request policy.
    expect($behaviour['CachePolicyId'])->toBe('658327ea-f89d-4fab-a63d-7e88639e58f6');
    expect($behaviour['OriginRequestPolicyId'])->toBe('');
    // The custom static-CORS response-headers policy, resolved at sync time.
    expect($behaviour['ResponseHeadersPolicyId'])->toBe('rhp-resolved-id');
    expect($behaviour['ViewerProtocolPolicy'])->toBe('redirect-to-https');
    expect($behaviour['Compress'])->toBeTrue();
});

it('sees no drift when the live behaviour already carries the managed fields', function (): void {
    // Realistic post-sync shape: CloudFront's GetDistributionConfig omits
    // OriginRequestPolicyId from the response when no policy is attached, even
    // though UpdateDistribution wrote it as ''. Filter must treat absent ↔ ''
    // as equivalent or every plan reports phantom `OriginRequestPolicyId:
    // absent → ` drift forever.
    $live = [
        'TargetOriginId' => 'asset-bucket',
        'ViewerProtocolPolicy' => 'redirect-to-https',
        'Compress' => true,
        'CachePolicyId' => '658327ea-f89d-4fab-a63d-7e88639e58f6',
        'ResponseHeadersPolicyId' => 'rhp-resolved-id',
        'MinTTL' => 0,
    ];

    expect(AssetDistribution::behaviourDrift($live, AssetDistribution::reconcilableBehaviour('rhp-resolved-id')))->toBe([]);
});

it('sees drift on a distribution still using the Origin-keyed cache policy', function (): void {
    // Shape of a live distribution from before the fix: custom Origin-in-key
    // cache policy, CORS-S3Origin origin-request policy forwarding Origin, no
    // response-headers policy. Reconciling must flip all three.
    $preFix = [
        'TargetOriginId' => 'asset-bucket',
        'ViewerProtocolPolicy' => 'redirect-to-https',
        'Compress' => true,
        'CachePolicyId' => 'custom-origin-keyed-id',
        'OriginRequestPolicyId' => '88a5eaf4-2fd4-4709-b370-b4c650ea3fcf',
        'ResponseHeadersPolicyId' => '',
        'MinTTL' => 0,
    ];

    $changes = AssetDistribution::behaviourDrift($preFix, AssetDistribution::reconcilableBehaviour('rhp-resolved-id'));

    expect(collect($changes)->pluck('attribute')->all())
        ->toEqualCanonicalizing(['CachePolicyId', 'OriginRequestPolicyId', 'ResponseHeadersPolicyId']);
});

it('pins every tracked 5xx to a zero error-caching TTL', function (): void {
    $errors = AssetDistribution::customErrorResponses();

    expect($errors['Quantity'])->toBe(4);
    expect(collect($errors['Items'])->pluck('ErrorCachingMinTTL')->unique()->all())->toBe([0]);
    expect(collect($errors['Items'])->pluck('ErrorCode')->all())->toBe([500, 502, 503, 504]);

    // AWS rejects UpdateDistribution with "The specified list of custom error
    // responses does not exist or is not valid" when ResponsePagePath /
    // ResponseCode are omitted. Pin them as empty strings on every item.
    expect(collect($errors['Items'])->pluck('ResponsePagePath')->unique()->all())->toBe(['']);
    expect(collect($errors['Items'])->pluck('ResponseCode')->unique()->all())->toBe(['']);
});

it('creates the response-headers policy with CorsConfig (not CustomHeadersConfig — AWS rejects ACAO there)', function (): void {
    // No existing policy by name → fall through to CreateResponseHeadersPolicy.
    $recorder = bindRecordingCloudFrontClient([
        'ListResponseHeadersPolicies' => new Result(['ResponseHeadersPolicyList' => ['Items' => []]]),
        'CreateResponseHeadersPolicy' => new Result(['ResponseHeadersPolicy' => ['Id' => 'rhp-new-id']]),
    ]);

    $resource = new AssetDistribution();
    $id = (new ReflectionMethod($resource, 'ensureResponseHeadersPolicy'))->invoke($resource);

    expect($id)->toBe('rhp-new-id');

    $create = collect($recorder->calls)->firstWhere('name', 'CreateResponseHeadersPolicy');
    expect($create)->not->toBeNull();

    $config = $create['args']['ResponseHeadersPolicyConfig'];

    // The bug we're guarding: putting ACAO in CustomHeadersConfig is rejected
    // by AWS ("Access-Control-Allow-Origin is a CORS header and cannot be set
    // as custom header"). CorsConfig is the right home for it.
    expect($config)->not->toHaveKey('CustomHeadersConfig');

    expect($config['CorsConfig']['AccessControlAllowOrigins'])->toBe([
        'Quantity' => 1,
        'Items' => ['*'],
    ]);
    expect($config['CorsConfig']['AccessControlAllowMethods']['Items'])->toBe(['GET', 'HEAD', 'OPTIONS']);
    expect($config['CorsConfig']['AccessControlAllowHeaders']['Items'])->toBe(['*']);
    // `*` is incompatible with credentials — must be false.
    expect($config['CorsConfig']['AccessControlAllowCredentials'])->toBeFalse();
    // Override origin's CORS headers if it ever sends any (it shouldn't —
    // the Origin header isn't forwarded to S3).
    expect($config['CorsConfig']['OriginOverride'])->toBeTrue();
});

it('reuses an existing response-headers policy by name (no Create call)', function (): void {
    $recorder = bindRecordingCloudFrontClient([
        'ListResponseHeadersPolicies' => new Result(['ResponseHeadersPolicyList' => [
            'Items' => [
                ['ResponseHeadersPolicy' => [
                    'Id' => 'rhp-existing-id',
                    'ResponseHeadersPolicyConfig' => ['Name' => 'yolo-asset-headers'],
                ]],
            ],
        ]]),
    ]);

    $resource = new AssetDistribution();
    $id = (new ReflectionMethod($resource, 'ensureResponseHeadersPolicy'))->invoke($resource);

    expect($id)->toBe('rhp-existing-id');
    expect(collect($recorder->calls)->pluck('name'))->not->toContain('CreateResponseHeadersPolicy');
});

/**
 * Bind an S3 client that records every command and returns the given responses
 * (looked up by command name; missing entries default to an empty Result, so
 * GetBucketPolicy reads as "no policy attached").
 *
 * @param  array<string, Result>  $byCommand
 */
function bindAssetS3Recorder(array $byCommand = []): object
{
    $recorder = new class($byCommand) extends MockHandler
    {
        /** @var array<int, array{name: string, args: array<string, mixed>}> */
        public array $calls = [];

        public function __construct(public array $byCommand) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            return Create::promiseFor($this->byCommand[$cmd->getName()] ?? new Result());
        }
    };

    Helpers::app()->instance('s3', new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $recorder,
    ]));

    return $recorder;
}

/**
 * A GetDistributionConfig result whose behaviour and error-caching are already
 * in the desired state, so the only drift in play is the origin passed in.
 */
function syncedDistributionConfigResult(string $originDomain): Result
{
    return new Result([
        'ETag' => 'etag-123',
        'DistributionConfig' => [
            'CallerReference' => 'ref-1',
            'Comment' => 'yolo-testing-my-app-assets',
            'Enabled' => true,
            'Origins' => ['Quantity' => 1, 'Items' => [[
                'Id' => 'asset-bucket',
                'DomainName' => $originDomain,
                'OriginAccessControlId' => 'oac-1',
                'S3OriginConfig' => ['OriginAccessIdentity' => ''],
            ]]],
            'DefaultCacheBehavior' => [
                'TargetOriginId' => 'asset-bucket',
                'ViewerProtocolPolicy' => 'redirect-to-https',
                'Compress' => true,
                'CachePolicyId' => '658327ea-f89d-4fab-a63d-7e88639e58f6',
                'ResponseHeadersPolicyId' => 'rhp-existing-id',
                'MinTTL' => 0,
            ],
            'CustomErrorResponses' => ['Quantity' => 4, 'Items' => [
                ['ErrorCode' => 500, 'ErrorCachingMinTTL' => 0, 'ResponsePagePath' => '', 'ResponseCode' => ''],
                ['ErrorCode' => 502, 'ErrorCachingMinTTL' => 0, 'ResponsePagePath' => '', 'ResponseCode' => ''],
                ['ErrorCode' => 503, 'ErrorCachingMinTTL' => 0, 'ResponsePagePath' => '', 'ResponseCode' => ''],
                ['ErrorCode' => 504, 'ErrorCachingMinTTL' => 0, 'ResponsePagePath' => '', 'ResponseCode' => ''],
            ]],
        ],
    ]);
}

/** The CloudFront mock set for a live distribution + resolvable headers policy. */
function syncedDistributionMocks(string $originDomain): array
{
    return [
        'ListDistributions' => new Result(['DistributionList' => ['Items' => [[
            'Comment' => 'yolo-testing-my-app-assets',
            'Id' => 'E123',
            'ARN' => 'arn:aws:cloudfront::111111111111:distribution/E123',
            'DomainName' => 'd123.cloudfront.net',
        ]]]]),
        'GetDistributionConfig' => syncedDistributionConfigResult($originDomain),
        'ListResponseHeadersPolicies' => new Result(['ResponseHeadersPolicyList' => [
            'Items' => [
                ['ResponseHeadersPolicy' => [
                    'Id' => 'rhp-existing-id',
                    'ResponseHeadersPolicyConfig' => ['Name' => 'yolo-asset-headers'],
                ]],
            ],
        ]]),
        // Additional metrics already on → no cdn-additional-metrics drift, so the
        // behaviour/policy assertions below stay isolated to what they test.
        'GetMonitoringSubscription' => new Result(['MonitoringSubscription' => [
            'RealtimeMetricsSubscriptionConfig' => ['RealtimeMetricsSubscriptionStatus' => 'Enabled'],
        ]]),
    ];
}

/** The exact OAC read policy the distribution owns on the asset bucket. */
function desiredOacReadPolicy(): array
{
    return [
        'Version' => '2012-10-17',
        'Statement' => [
            [
                'Sid' => 'AllowCloudFrontServicePrincipalReadOnly',
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'cloudfront.amazonaws.com'],
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::yolo-111111111111-testing-my-app-assets/*',
                'Condition' => ['StringEquals' => ['AWS:SourceArn' => 'arn:aws:cloudfront::111111111111:distribution/E123']],
            ],
        ],
    ];
}

it('repoints a drifted origin and grants the OAC read policy on the new bucket', function (): void {
    $cloudFront = bindRecordingCloudFrontClient(
        syncedDistributionMocks('yolo-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com'),
    );
    $s3 = bindAssetS3Recorder();

    $changes = (new AssetDistribution())->synchroniseConfiguration();

    expect(collect($changes)->pluck('attribute')->all())
        ->toEqualCanonicalizing(['origin', 'asset-bucket-policy']);

    // the distribution update carries the repointed origin + the read ETag
    $update = collect($cloudFront->calls)->firstWhere('name', 'UpdateDistribution');
    expect($update['args']['DistributionConfig']['Origins']['Items'][0]['DomainName'])
        ->toBe('yolo-111111111111-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com')
        ->and($update['args']['IfMatch'])->toBe('etag-123');

    // the new bucket gets the OAC read grant, scoped to this distribution only
    $put = collect($s3->calls)->firstWhere('name', 'PutBucketPolicy');
    expect($put['args']['Bucket'])->toBe('yolo-111111111111-testing-my-app-assets')
        ->and(json_decode((string) $put['args']['Policy'], true))->toBe(desiredOacReadPolicy());
});

it('does not rewrite the bucket policy when only a distribution field drifted', function (): void {
    $cloudFront = bindRecordingCloudFrontClient(
        syncedDistributionMocks('yolo-111111111111-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com'),
    );
    // behaviour drift: stale cache policy on the live distribution
    $config = $cloudFront->byCommand['GetDistributionConfig']->toArray();
    $config['DistributionConfig']['DefaultCacheBehavior']['CachePolicyId'] = 'custom-old-id';
    $cloudFront->byCommand['GetDistributionConfig'] = new Result($config);

    $s3 = bindAssetS3Recorder([
        'GetBucketPolicy' => new Result(['Policy' => json_encode(desiredOacReadPolicy())]),
    ]);

    $changes = (new AssetDistribution())->synchroniseConfiguration();

    expect(collect($changes)->pluck('attribute')->all())->toBe(['CachePolicyId']);
    expect(collect($cloudFront->calls)->pluck('name'))->toContain('UpdateDistribution');
    expect(collect($s3->calls)->pluck('name'))->not->toContain('PutBucketPolicy');
});

it('heals a missing OAC grant without a needless distribution redeploy', function (): void {
    // origin + behaviour all in the desired state; only the bucket policy is gone
    $cloudFront = bindRecordingCloudFrontClient(
        syncedDistributionMocks('yolo-111111111111-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com'),
    );
    $s3 = bindAssetS3Recorder();

    $changes = (new AssetDistribution())->synchroniseConfiguration();

    expect(collect($changes)->pluck('attribute')->all())->toBe(['asset-bucket-policy']);
    expect(collect($s3->calls)->pluck('name'))->toContain('PutBucketPolicy');
    // a policy-only fix must never trigger a ~15 min edge redeploy
    expect(collect($cloudFront->calls)->pluck('name'))->not->toContain('UpdateDistribution');
});

it('computes origin and policy drift without writing under apply:false', function (): void {
    $cloudFront = bindRecordingCloudFrontClient(
        syncedDistributionMocks('yolo-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com'),
    );
    $s3 = bindAssetS3Recorder();

    $changes = (new AssetDistribution())->synchroniseConfiguration(apply: false);

    expect(collect($changes)->pluck('attribute')->all())
        ->toEqualCanonicalizing(['origin', 'asset-bucket-policy']);
    expect(collect($cloudFront->calls)->pluck('name'))->not->toContain('UpdateDistribution');
    expect(collect($s3->calls)->pluck('name'))->not->toContain('PutBucketPolicy');
});

it('turns on additional CloudFront metrics when the monitoring subscription is off', function (): void {
    // Origin, behaviour and bucket policy all in sync → the only drift is the
    // metrics subscription being off (empty GetMonitoringSubscription result).
    $mocks = syncedDistributionMocks('yolo-111111111111-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com');
    $mocks['GetMonitoringSubscription'] = new Result();
    $cloudFront = bindRecordingCloudFrontClient($mocks);
    bindAssetS3Recorder(['GetBucketPolicy' => new Result(['Policy' => json_encode(desiredOacReadPolicy())])]);

    $changes = (new AssetDistribution())->synchroniseConfiguration();

    expect(collect($changes)->pluck('attribute')->all())->toBe(['cdn-additional-metrics']);

    $create = collect($cloudFront->calls)->firstWhere('name', 'CreateMonitoringSubscription');
    expect($create)->not->toBeNull()
        ->and($create['args']['MonitoringSubscription']['RealtimeMetricsSubscriptionConfig']['RealtimeMetricsSubscriptionStatus'])->toBe('Enabled');
    // A metrics-only fix must never trigger a ~15 min edge redeploy.
    expect(collect($cloudFront->calls)->pluck('name'))->not->toContain('UpdateDistribution');
});

it('reports the metrics-subscription drift without enabling it under apply:false', function (): void {
    $mocks = syncedDistributionMocks('yolo-111111111111-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com');
    $mocks['GetMonitoringSubscription'] = new Result();
    $cloudFront = bindRecordingCloudFrontClient($mocks);
    bindAssetS3Recorder(['GetBucketPolicy' => new Result(['Policy' => json_encode(desiredOacReadPolicy())])]);

    $changes = (new AssetDistribution())->synchroniseConfiguration(apply: false);

    expect(collect($changes)->pluck('attribute')->all())->toBe(['cdn-additional-metrics']);
    expect(collect($cloudFront->calls)->pluck('name'))->not->toContain('CreateMonitoringSubscription');
});

it('detects origin drift against the current asset bucket name', function (): void {
    // A distribution still pointing at a pre-rename bucket must drift, so a
    // bucket rename converges through sync instead of half-applying.
    $drift = AssetDistribution::originDrift(['Items' => [[
        'DomainName' => 'yolo-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com',
    ]]]);

    expect($drift)->not->toBeNull()
        ->and($drift->attribute)->toBe('origin')
        ->and($drift->to)->toBe('yolo-111111111111-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com');

    // Pointing at the current bucket's regional endpoint → in sync.
    expect(AssetDistribution::originDrift(['Items' => [[
        'DomainName' => 'yolo-111111111111-testing-my-app-assets.s3.ap-southeast-2.amazonaws.com',
    ]]]))->toBeNull();
});

it('detects error-caching drift', function (): void {
    // Unset → CloudFront's ~10s default caches a transient 5xx → drift.
    expect(AssetDistribution::errorCachingDrift([]))->not->toBeNull();

    // A partial / non-zero TTL config still drifts.
    expect(AssetDistribution::errorCachingDrift([
        'Items' => [['ErrorCode' => 503, 'ErrorCachingMinTTL' => 10]],
    ]))->not->toBeNull();

    // All four codes pinned to 0 (extra default fields and ordering ignored) → in sync.
    expect(AssetDistribution::errorCachingDrift([
        'Items' => [
            ['ErrorCode' => 504, 'ErrorCachingMinTTL' => 0, 'ResponsePagePath' => '', 'ResponseCode' => ''],
            ['ErrorCode' => 500, 'ErrorCachingMinTTL' => 0, 'ResponsePagePath' => '', 'ResponseCode' => ''],
            ['ErrorCode' => 502, 'ErrorCachingMinTTL' => 0, 'ResponsePagePath' => '', 'ResponseCode' => ''],
            ['ErrorCode' => 503, 'ErrorCachingMinTTL' => 0, 'ResponsePagePath' => '', 'ResponseCode' => ''],
        ],
    ]))->toBeNull();
});
