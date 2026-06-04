<?php

use Aws\Result;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\ConfigureEnvAndVersionStep;

function rebuildEnvFixture(array $config): void
{
    writeManifest($config);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_exists(Paths::build('.env.testing')) && unlink(Paths::build('.env.testing'));
}

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
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

    // Web-app manifests default cache.store to redis, so the build resolves the
    // Valkey endpoint — bind a default cluster lookup for any tasks.web test.
    $captured = [];
    bindMockElastiCacheClient([
        'DescribeReplicationGroups' => new Result(['ReplicationGroups' => [
            [
                'ReplicationGroupId' => 'yolo-testing-cache',
                'NodeGroups' => [['PrimaryEndpoint' => ['Address' => 'master.yolo-testing-cache.cache.amazonaws.com']]],
            ],
        ]]),
    ], $captured);
});

it('always points ASSET_URL at the CloudFront distribution, versioned per build', function () {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('APP_VERSION=26.21.5.0611');
    expect($env)->toContain('ASSET_URL=https://d123abc.cloudfront.net/builds/26.21.5.0611');
});

it('injects AWS_BUCKET from the manifest when the .env does not define it', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_exists(Paths::build('.env.testing')) && unlink(Paths::build('.env.testing'));

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->toContain('AWS_BUCKET=my-app-bucket');
});

it('does not inject AWS_BUCKET when the manifest does not define one', function () {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->not->toContain('AWS_BUCKET=');
});

it('injects the SQS connection block when tasks.web.queue is on', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => true]],
    ]);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_exists(Paths::build('.env.testing')) && unlink(Paths::build('.env.testing'));

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->toContain('AWS_DEFAULT_REGION=ap-southeast-2');
    expect($env)->toContain('SQS_PREFIX=https://sqs.ap-southeast-2.amazonaws.com/111111111111');
    expect($env)->toContain('SQS_QUEUE=yolo-testing-my-app');
});

it('forces QUEUE_CONNECTION=sync when the queue is off (no worker to consume)', function () {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=sync');
    expect($env)->not->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->not->toContain('SQS_PREFIX=');
    // region is still useful (S3 etc.), so it's injected regardless of the queue
    expect($env)->toContain('AWS_DEFAULT_REGION=ap-southeast-2');
});

it('respects a QUEUE_CONNECTION already set in the .env', function () {
    file_put_contents(Paths::build('.env.testing'), "QUEUE_CONNECTION=redis\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=redis');
    expect($env)->not->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->not->toContain('QUEUE_CONNECTION=sync');
});

it('does not pin SQS_QUEUE for a multitenant app (worker resolves it per tenant)', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => true]],
        'tenants' => ['acme' => ['domain' => 'acme.test']],
    ]);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_exists(Paths::build('.env.testing')) && unlink(Paths::build('.env.testing'));

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->not->toContain('SQS_QUEUE=');
});

it('respects an AWS_BUCKET already set in the .env', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    is_dir(Paths::build()) || mkdir(Paths::build(), 0755, true);
    file_put_contents(Paths::build('.env.testing'), "AWS_BUCKET=custom-bucket\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('AWS_BUCKET=custom-bucket');
    expect($env)->not->toContain('AWS_BUCKET=my-app-bucket');
});

it('wires the redis cache env to the Valkey cluster when cache.store is redis', function () {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
    ]);

    $captured = [];
    bindMockElastiCacheClient([
        'DescribeReplicationGroups' => new Result(['ReplicationGroups' => [
            [
                'ReplicationGroupId' => 'yolo-testing-cache',
                'NodeGroups' => [['PrimaryEndpoint' => ['Address' => 'master.yolo-testing-cache.cache.amazonaws.com']]],
            ],
        ]]),
    ], $captured);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('CACHE_STORE=redis');
    expect($env)->toContain('REDIS_HOST=master.yolo-testing-cache.cache.amazonaws.com');
    expect($env)->toContain('REDIS_PORT=6379');
    expect($env)->toContain('REDIS_PREFIX=yolo-testing-my-app_');
});

it('does not wire cache or session for a non-web app (no tasks.web)', function () {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->not->toContain('CACHE_STORE=');
    expect($env)->not->toContain('REDIS_HOST=');
    expect($env)->not->toContain('SESSION_DRIVER=');
});

it('defaults a web app to the shared redis cache and redis sessions when neither is set', function () {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('CACHE_STORE=redis');
    expect($env)->toContain('REDIS_HOST=master.yolo-testing-cache.cache.amazonaws.com');
    expect($env)->toContain('SESSION_DRIVER=redis');
    // No SESSION_CONNECTION — a null connection routes the redis session handler
    // to the stock default connection (DB 0), keeping sessions off the cache
    // connection (DB 1).
    expect($env)->not->toContain('SESSION_CONNECTION');
});

it('does not inject OCTANE_SERVER — the app owns it (seeded by yolo init)', function () {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->not->toContain('OCTANE_SERVER');
});

it('pins SESSION_DRIVER from the manifest', function () {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'database'],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('SESSION_DRIVER=database');
});

it('does not pin SESSION_DRIVER when the manifest does not select one', function () {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->not->toContain('SESSION_DRIVER=');
});

it('respects a SESSION_DRIVER already set in the .env', function () {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'database'],
    ]);

    file_put_contents(Paths::build('.env.testing'), "SESSION_DRIVER=cookie\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('SESSION_DRIVER=cookie');
    expect($env)->not->toContain('SESSION_DRIVER=database');
});
