<?php

use Aws\Result;
use Aws\MockHandler;
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
    // Shape of the live CL distribution before the fix: custom Origin-in-key
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
