<?php

use Codinglabs\Yolo\Resources\Cdn\AssetDistribution;

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
