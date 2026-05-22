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
