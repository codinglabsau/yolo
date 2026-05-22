<?php

use Codinglabs\Yolo\Resources\Cdn\Distribution;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

it('names + tags per app + environment', function () {
    expect((new Distribution())->name())->toBe('yolo-testing-my-app-cdn');
    expect((new Distribution())->tags())->toBe(['Name' => 'yolo-testing-my-app-cdn']);
});

it('resolves its domain from the distribution matching its comment', function () {
    bindMockCloudFrontClient([
        [
            'Comment' => 'yolo-testing-my-app-cdn',
            'DomainName' => 'd123abc.cloudfront.net',
            'ARN' => 'arn:aws:cloudfront::111111111111:distribution/E123',
            'Id' => 'E123',
        ],
    ]);

    expect((new Distribution())->exists())->toBeTrue();
    expect((new Distribution())->domain())->toBe('d123abc.cloudfront.net');
    expect((new Distribution())->arn())->toBe('arn:aws:cloudfront::111111111111:distribution/E123');
});

it('reports not-exists when no distribution matches its comment', function () {
    bindMockCloudFrontClient([
        ['Comment' => 'yolo-testing-other-app-cdn', 'DomainName' => 'd999.cloudfront.net', 'ARN' => 'arn:...', 'Id' => 'E999'],
    ]);

    expect((new Distribution())->exists())->toBeFalse();
});
