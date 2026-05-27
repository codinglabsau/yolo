<?php

use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

it('names the asset bucket per app + environment', function () {
    expect((new AssetBucket())->name())->toBe('yolo-testing-my-app-assets');
});

it('derives the bucket ARN from the name', function () {
    expect((new AssetBucket())->arn())->toBe('arn:aws:s3:::yolo-testing-my-app-assets');
});

it('tags the bucket with its name and app owner', function () {
    expect((new AssetBucket())->tags())->toBe(['Name' => 'yolo-testing-my-app-assets', 'yolo:app' => 'my-app']);
});

it('reconciles a CORS configuration so the origin serves Access-Control-Allow-Origin', function () {
    expect(new AssetBucket())->toBeInstanceOf(SynchronisesConfiguration::class);
});
