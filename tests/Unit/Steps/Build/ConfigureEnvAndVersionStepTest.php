<?php

use Aws\Result;
use Dotenv\Dotenv;
use Codinglabs\Yolo\Paths;
use GuzzleHttp\Psr7\Response;
use Aws\Command as AwsCommand;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Steps\Build\ConfigureEnvAndVersionStep;

function rebuildEnvFixture(array $config): void
{
    writeManifest($config);

    if (! is_dir(Paths::build())) {
        mkdir(Paths::build(), 0755, true);
    }
    if (file_exists(Paths::build('.env.testing'))) {
        unlink(Paths::build('.env.testing'));
    }
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    if (! is_dir(Paths::build())) {
        mkdir(Paths::build(), 0755, true);
    }
    if (file_exists(Paths::build('.env.testing'))) {
        unlink(Paths::build('.env.testing'));
    }

    bindMockCloudFrontClient([
        [
            'Comment' => 'yolo-testing-my-app-assets',
            'DomainName' => 'd123abc.cloudfront.net',
            'ARN' => 'arn:aws:cloudfront::111111111111:distribution/E123',
            'Id' => 'E123',
        ],
    ]);

    // The build merges the app's env-side `.env` (env/.env.{app}) — absent by
    // default (it only exists once a key is minted), so every existing test
    // reads NoSuchKey and just omits it. Tests that need the file present
    // rebind the S3 client.
    $s3Captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('Not found', new AwsCommand('GetObject'), [
            'code' => 'NoSuchKey',
            'response' => new Response(404),
        ]),
    ], $s3Captured);

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

it('always points ASSET_URL at the CloudFront distribution, versioned per build', function (): void {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('APP_VERSION=26.21.5.0611');
    expect($env)->toContain('ASSET_URL=https://d123abc.cloudfront.net/builds/26.21.5.0611');
    // VITE_ASSET_URL references ASSET_URL (the stock `VITE_APP_NAME="${APP_NAME}"`
    // idiom); written as a reference, it must resolve to the same versioned prefix
    // when phpdotenv parses the file — which is exactly how Vite's env is built.
    expect($env)->toContain('VITE_ASSET_URL=${ASSET_URL}');
    expect(Dotenv::parse($env)['VITE_ASSET_URL'])
        ->toBe('https://d123abc.cloudfront.net/builds/26.21.5.0611');
});

it('injects AWS_BUCKET from the manifest when the .env does not define it', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    if (! is_dir(Paths::build())) {
        mkdir(Paths::build(), 0755, true);
    }
    if (file_exists(Paths::build('.env.testing'))) {
        unlink(Paths::build('.env.testing'));
    }

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->toContain('AWS_BUCKET=my-app-bucket');
});

it('does not inject AWS_BUCKET when the manifest does not define one', function (): void {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->not->toContain('AWS_BUCKET=');
});

it('injects FILESYSTEM_DISK=s3 when a bucket is set', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->toContain('FILESYSTEM_DISK=s3');
});

it('does not inject FILESYSTEM_DISK when no bucket is set', function (): void {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->not->toContain('FILESYSTEM_DISK=');
});

it('respects a FILESYSTEM_DISK already set in the .env', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);
    file_put_contents(Paths::build('.env.testing'), "FILESYSTEM_DISK=local\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('FILESYSTEM_DISK=local')
        ->not->toContain('FILESYSTEM_DISK=s3');
});

it('defaults APP_ENV to the environment name', function (): void {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->toContain('APP_ENV=testing');
});

it('respects an APP_ENV already set in the .env', function (): void {
    file_put_contents(Paths::build('.env.testing'), "APP_ENV=staging\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('APP_ENV=staging')
        ->not->toContain('APP_ENV=testing');
});

it('wires the SQS connection for every web app (the worker always runs somewhere)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    if (! is_dir(Paths::build())) {
        mkdir(Paths::build(), 0755, true);
    }
    if (file_exists(Paths::build('.env.testing'))) {
        unlink(Paths::build('.env.testing'));
    }

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->toContain('AWS_DEFAULT_REGION=ap-southeast-2');
    expect($env)->toContain('SQS_PREFIX=https://sqs.ap-southeast-2.amazonaws.com/111111111111');
    expect($env)->toContain('SQS_QUEUE=yolo-testing-my-app');
});

it('forces QUEUE_CONNECTION=sync for a non-web app (no worker to consume)', function (): void {
    // No tasks.web → no container, so no queue worker; routing to SQS would dead-end.
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=sync');
    expect($env)->not->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->not->toContain('SQS_PREFIX=');
    // region is still useful (S3 etc.), so it's injected regardless of the queue
    expect($env)->toContain('AWS_DEFAULT_REGION=ap-southeast-2');
});

it('respects a QUEUE_CONNECTION already set in the .env when a worker runs', function (): void {
    // A worker exists (web app), so YOLO's sqs default yields to a developer override.
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
    file_put_contents(Paths::build('.env.testing'), "QUEUE_CONNECTION=redis\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=redis');
    expect($env)->not->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->not->toContain('QUEUE_CONNECTION=sync');
});

it('forces QUEUE_CONNECTION=sync when the queue is disabled (tasks.queue: false)', function (): void {
    // A worker would otherwise be bundled in web, but tasks.queue: false switches it
    // off entirely — so jobs must run inline.
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => false],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=sync');
    expect($env)->not->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->not->toContain('SQS_PREFIX=');
});

it('wires the SQS connection for a headless standalone queue (a worker with no web tier)', function (): void {
    // The connection follows the worker, not the web tier: a headless app whose only
    // service is a standalone queue still consumes SQS.
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['queue' => true],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->toContain('SQS_PREFIX=https://sqs.ap-southeast-2.amazonaws.com/111111111111');
    expect($env)->toContain('SQS_QUEUE=yolo-testing-my-app');
});

it('hard-fails when QUEUE_CONNECTION is non-sync but no worker runs', function (): void {
    // No worker (the default no-tasks manifest) + a non-sync override = jobs black-holed,
    // so the build refuses rather than shipping a silently broken image.
    file_put_contents(Paths::build('.env.testing'), "QUEUE_CONNECTION=redis\n");

    expect(fn (): mixed => (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']))
        ->toThrow(IntegrityCheckException::class);
});

it('does not pin SQS_QUEUE for a multitenant app (worker resolves it per tenant)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
        'tenants' => ['acme' => ['domain' => 'acme.test']],
    ]);

    if (! is_dir(Paths::build())) {
        mkdir(Paths::build(), 0755, true);
    }
    if (file_exists(Paths::build('.env.testing'))) {
        unlink(Paths::build('.env.testing'));
    }

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('QUEUE_CONNECTION=sqs');
    expect($env)->not->toContain('SQS_QUEUE=');
});

it('respects an AWS_BUCKET already set in the .env', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    if (! is_dir(Paths::build())) {
        mkdir(Paths::build(), 0755, true);
    }
    file_put_contents(Paths::build('.env.testing'), "AWS_BUCKET=custom-bucket\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('AWS_BUCKET=custom-bucket');
    expect($env)->not->toContain('AWS_BUCKET=my-app-bucket');
});

it('wires the redis cache env to the Valkey cluster when cache.store is redis', function (): void {
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

it('does not wire cache or session for a non-web app (no tasks.web)', function (): void {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->not->toContain('CACHE_STORE=');
    expect($env)->not->toContain('REDIS_HOST=');
    expect($env)->not->toContain('SESSION_DRIVER=');
});

it('defaults a web app to the shared redis cache and redis sessions when neither is set', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
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

it('enforces the platform-invariant env values (LOG_CHANNEL, OCTANE_HTTPS, OCTANE_SERVER)', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('LOG_CHANNEL=stderr')
        ->toContain('OCTANE_HTTPS=true')
        ->toContain('OCTANE_SERVER=frankenphp');
});

it('hard-fails when LOG_CHANNEL is set to a conflicting value', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
    file_put_contents(Paths::build('.env.testing'), "LOG_CHANNEL=daily\n");

    expect(fn (): mixed => (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']))
        ->toThrow(IntegrityCheckException::class);
});

it('hard-fails when OCTANE_HTTPS is set to a conflicting value', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
    file_put_contents(Paths::build('.env.testing'), "OCTANE_HTTPS=false\n");

    expect(fn (): mixed => (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']))
        ->toThrow(IntegrityCheckException::class);
});

it('hard-fails when OCTANE_SERVER is set to a conflicting value', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
    file_put_contents(Paths::build('.env.testing'), "OCTANE_SERVER=swoole\n");

    expect(fn (): mixed => (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']))
        ->toThrow(IntegrityCheckException::class);
});

it('does not hard-fail when the app already sets the required platform value', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
    file_put_contents(Paths::build('.env.testing'), "LOG_CHANNEL=stderr\nOCTANE_SERVER=frankenphp\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))
        ->toContain('LOG_CHANNEL=stderr')
        ->toContain('OCTANE_SERVER=frankenphp');
});

it('pins SESSION_DRIVER from the manifest', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'database'],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('SESSION_DRIVER=database');
});

it('does not pin SESSION_DRIVER when the manifest does not select one', function (): void {
    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->not->toContain('SESSION_DRIVER=');
});

it('enables Inertia SSR when tasks.web.ssr is on', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true]],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->toContain('INERTIA_SSR_ENABLED=true');
});

it('does not enable Inertia SSR when tasks.web.ssr is off', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    expect(file_get_contents(Paths::build('.env.testing')))->not->toContain('INERTIA_SSR_ENABLED');
});

it('respects an INERTIA_SSR_ENABLED already set in the .env', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['ssr' => true]],
    ]);

    file_put_contents(Paths::build('.env.testing'), "INERTIA_SSR_ENABLED=false\n");

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('INERTIA_SSR_ENABLED=false');
    expect($env)->not->toContain('INERTIA_SSR_ENABLED=true');
});

it('respects a SESSION_DRIVER already set in the .env', function (): void {
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

it('merges the app env-side .env (env/.env.{app}) into the built env when present', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    // The env-side file carries YOLO-minted per-app secrets (the Typesense
    // scoped key). When it exists, its vars are baked into the image like any
    // buildValues entry.
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "TYPESENSE_API_KEY=scoped-key\n"]),
    ], $captured);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('TYPESENSE_API_KEY=scoped-key');
    // The read targets exactly this app's env-side key in the env config bucket.
    $get = collect($captured)->firstWhere('name', 'GetObject');
    expect($get['args']['Bucket'])->toBe('yolo-111111111111-testing-config')
        ->and($get['args']['Key'])->toBe('env/.env.my-app');
});

it('builds fine when the app env-side .env is absent (S3 not-found is skipped silently)', function (): void {
    rebuildEnvFixture([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    // No env-side file (the common case until a key is minted): GetObject throws
    // NoSuchKey. The build must succeed and simply omit those vars — never crash.
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('Not found', new AwsCommand('GetObject'), [
            'code' => 'NoSuchKey',
            'response' => new Response(404),
        ]),
    ], $captured);

    (new ConfigureEnvAndVersionStep('testing'))(['app-version' => '26.21.5.0611']);

    $env = file_get_contents(Paths::build('.env.testing'));

    expect($env)->toContain('APP_VERSION=26.21.5.0611')
        ->not->toContain('TYPESENSE_API_KEY=');
});
