<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\ConfigureEnvAndVersionStep;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_exists(Paths::build('.env.testing')) && unlink(Paths::build('.env.testing'));
});

it('does not set ASSET_URL when no CDN is configured (Fargate serves public/build directly)', function () {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('APP_VERSION=26.21.5.0611');
    expect($env)->not->toContain('ASSET_URL');
    // The alpha versioned-asset path must never be baked in on Fargate.
    expect($env)->not->toContain('builds/26.21.5.0611');
});

it('sets ASSET_URL to the configured CDN without a version prefix', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'asset-url' => 'https://cdn.example.com',
    ]);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_exists(Paths::build('.env.testing')) && unlink(Paths::build('.env.testing'));

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('ASSET_URL=https://cdn.example.com');
    expect($env)->not->toContain('builds/26.21.5.0611');
});

it('points ASSET_URL at the CloudFront distribution when assets.cloudfront is enabled', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'assets' => ['cloudfront' => true],
    ]);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_exists(Paths::build('.env.testing')) && unlink(Paths::build('.env.testing'));

    bindMockCloudFrontClient([
        [
            'Comment' => 'yolo-testing-my-app-assets',
            'DomainName' => 'd123abc.cloudfront.net',
            'ARN' => 'arn:aws:cloudfront::111111111111:distribution/E123',
            'Id' => 'E123',
        ],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))
        ->toContain('ASSET_URL=https://d123abc.cloudfront.net/builds/26.21.5.0611');
});

it('injects AWS_BUCKET from the manifest when the .env does not define it', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket'],
    ]);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_exists(Paths::build('.env.testing')) && unlink(Paths::build('.env.testing'));

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->toContain('AWS_BUCKET=my-app-bucket');
});

it('respects an AWS_BUCKET already set in the .env', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket'],
    ]);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_put_contents(Paths::build('.env.testing'), "AWS_BUCKET=custom-bucket\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('AWS_BUCKET=custom-bucket');
    expect($env)->not->toContain('AWS_BUCKET=my-app-bucket');
});
