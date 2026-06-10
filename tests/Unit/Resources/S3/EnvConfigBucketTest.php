<?php

use Aws\Result;
use Aws\Command;
use GuzzleHttp\Psr7\Response;
use Codinglabs\Yolo\Enums\Scope;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\S3\EnvConfigBucket;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncEnvConfigBucketStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is the env-scoped sibling of the per-app config buckets', function (): void {
    $bucket = new EnvConfigBucket();

    expect($bucket->name())->toBe('yolo-111111111111-testing-config')
        ->and($bucket->scope())->toBe(Scope::Env)
        ->and($bucket->tags())->toBe(['Name' => 'yolo-111111111111-testing-config', 'yolo:scope' => 'env']);
});

it('locks down and versions the newly created env config bucket', function (): void {
    $captured = [];

    bindRoutedS3Client([
        // missing on the first check, then present for the BucketExists waiter
        'HeadBucket' => [
            new S3Exception('Not Found', new Command('HeadBucket'), ['response' => new Response(404)]),
            new Result(['@metadata' => ['statusCode' => 200]]),
        ],
        'CreateBucket' => new Result(),
        'PutBucketTagging' => new Result(),
        'PutPublicAccessBlock' => new Result(),
        'PutBucketVersioning' => new Result(),
    ], $captured);

    expect((new SyncEnvConfigBucketStep())([]))->toBe(StepResult::CREATED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('CreateBucket')
        ->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketVersioning');

    $blockPublicAccess = collect($captured)->firstWhere('name', 'PutPublicAccessBlock');
    expect($blockPublicAccess['args']['PublicAccessBlockConfiguration'])->toBe([
        'BlockPublicAcls' => true,
        'IgnorePublicAcls' => true,
        'BlockPublicPolicy' => true,
        'RestrictPublicBuckets' => true,
    ]);

    $versioning = collect($captured)->firstWhere('name', 'PutBucketVersioning');
    expect($versioning['args']['VersioningConfiguration']['Status'])->toBe('Enabled');
});

it('reports drift but does not mutate the bucket during a dry-run', function (): void {
    $captured = [];

    bindRoutedS3Client([
        'HeadBucket' => new Result(),   // exists; hardening reads come back empty → drift
    ], $captured);

    expect((new SyncEnvConfigBucketStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);

    $names = array_column($captured, 'name');
    expect($names)->not->toContain('PutPublicAccessBlock')
        ->not->toContain('PutBucketVersioning')
        ->not->toContain('PutBucketTagging');
});
