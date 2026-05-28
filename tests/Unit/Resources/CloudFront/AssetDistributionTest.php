<?php

use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

it('names + tags per app + environment', function () {
    expect((new AssetDistribution())->name())->toBe('yolo-testing-my-app-assets');
    expect((new AssetDistribution())->tags())->toBe(['Name' => 'yolo-testing-my-app-assets', 'yolo:app' => 'my-app']);
});

it('resolves its domain from the distribution matching its comment', function () {
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

it('reports not-exists when no distribution matches its comment', function () {
    bindMockCloudFrontClient([
        ['Comment' => 'yolo-testing-other-app-assets', 'DomainName' => 'd999.cloudfront.net', 'ARN' => 'arn:...', 'Id' => 'E999'],
    ]);

    expect((new AssetDistribution())->exists())->toBeFalse();
});

it('reconciles the managed cache-behaviour policy fields', function () {
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

it('sees no drift when the live behaviour already carries the managed fields', function () {
    $live = [
        'TargetOriginId' => 'asset-bucket',
        'ViewerProtocolPolicy' => 'redirect-to-https',
        'Compress' => true,
        'CachePolicyId' => '658327ea-f89d-4fab-a63d-7e88639e58f6',
        'OriginRequestPolicyId' => '',
        'ResponseHeadersPolicyId' => 'rhp-resolved-id',
        'MinTTL' => 0,
    ];

    // No update should fire — merging the managed fields leaves the block unchanged.
    expect(array_merge($live, AssetDistribution::reconcilableBehaviour('rhp-resolved-id')) == $live)->toBeTrue();
});

it('sees drift on a distribution still using the Origin-keyed cache policy', function () {
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

    expect(array_merge($preFix, AssetDistribution::reconcilableBehaviour('rhp-resolved-id')) == $preFix)->toBeFalse();
});

it('pins every tracked 5xx to a zero error-caching TTL', function () {
    $errors = AssetDistribution::customErrorResponses();

    expect($errors['Quantity'])->toBe(4);
    expect(collect($errors['Items'])->pluck('ErrorCachingMinTTL')->unique()->all())->toBe([0]);
    expect(collect($errors['Items'])->pluck('ErrorCode')->all())->toBe([500, 502, 503, 504]);
});

it('detects error-caching drift', function () {
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
