<?php

use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

it('names + tags per app + environment', function () {
    expect((new AssetDistribution())->name())->toBe('yolo-testing-my-app-assets');
    expect((new AssetDistribution())->tags())->toBe(['Name' => 'yolo-testing-my-app-assets']);
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
    $behaviour = AssetDistribution::reconcilableBehaviour('cp-resolved-id');

    // CORS is served by the S3 origin (CORS-S3Origin origin-request policy
    // forwards Origin); no response-headers policy, so ACAO can't double up.
    expect($behaviour['OriginRequestPolicyId'])->toBe('88a5eaf4-2fd4-4709-b370-b4c650ea3fcf');
    expect($behaviour['ResponseHeadersPolicyId'])->toBe('');
    // The custom Origin-in-key cache policy, resolved at sync time.
    expect($behaviour['CachePolicyId'])->toBe('cp-resolved-id');
    expect($behaviour['ViewerProtocolPolicy'])->toBe('redirect-to-https');
    expect($behaviour['Compress'])->toBeTrue();
});

it('sees no drift when the live behaviour already carries the managed fields', function () {
    $live = [
        'TargetOriginId' => 'asset-bucket',
        'ViewerProtocolPolicy' => 'redirect-to-https',
        'Compress' => true,
        'CachePolicyId' => 'cp-resolved-id',
        'OriginRequestPolicyId' => '88a5eaf4-2fd4-4709-b370-b4c650ea3fcf',
        'ResponseHeadersPolicyId' => '',
        'MinTTL' => 0,
    ];

    // No update should fire — merging the managed fields leaves the block unchanged.
    expect(array_merge($live, AssetDistribution::reconcilableBehaviour('cp-resolved-id')) == $live)->toBeTrue();
});

it('sees drift on a distribution still using the response-headers CORS policy', function () {
    // Shape of the live CL distribution before the fix: SimpleCORS policy, no
    // origin-request policy, managed CachingOptimized. Reconciling must flip all.
    $preFix = [
        'TargetOriginId' => 'asset-bucket',
        'ViewerProtocolPolicy' => 'redirect-to-https',
        'Compress' => true,
        'CachePolicyId' => '658327ea-f89d-4fab-a63d-7e88639e58f6',
        'ResponseHeadersPolicyId' => '60669652-455b-4ae9-85a4-c4c02393f86c',
        'MinTTL' => 0,
    ];

    expect(array_merge($preFix, AssetDistribution::reconcilableBehaviour('cp-resolved-id')) == $preFix)->toBeFalse();
});
