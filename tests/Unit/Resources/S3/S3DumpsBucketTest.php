<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\S3\S3DumpsBucket;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * Bind an S3 client that records every command and returns the given responses
 * (looked up by command name; missing entries default to an empty Result).
 * Returns a recorder with a `calls` array of `['name', 'args']` entries.
 * (File-local twin of the logs bucket test's helper — parallel Pest runs load
 * each file in its own worker, so helpers can't be shared across siblings.)
 *
 * @param  array<string, Result>  $byCommand
 */
function bindRecordingDumpsS3Client(array $byCommand = []): object
{
    $recorder = new class($byCommand) extends MockHandler
    {
        /** @var array<int, array{name: string, args: array<string, mixed>}> */
        public array $calls = [];

        public function __construct(public array $byCommand) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            return Create::promiseFor($this->byCommand[$cmd->getName()] ?? new Result());
        }
    };

    Helpers::app()->instance('s3', new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $recorder,
    ]));

    return $recorder;
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is env-scoped and named yolo-{account-id}-{env}-dumps', function (): void {
    $bucket = new S3DumpsBucket();

    expect($bucket->name())->toBe('yolo-111111111111-testing-dumps')
        ->and($bucket->scope())->toBe(Scope::Env)
        // env-scoped → yolo:scope=env, no yolo:app owner tag
        ->and($bucket->tags())->toBe(['Name' => 'yolo-111111111111-testing-dumps', 'yolo:scope' => 'env']);
});

it('is deliberately not Deletable — dumps outlive environment teardown', function (): void {
    // A database dump is the one artefact whose purpose is to survive the loss
    // of everything else; teardown must never sweep it up.
    expect(new S3DumpsBucket())->not->toBeInstanceOf(Deletable::class);
});

it('reconciles BPA + versioning + the retention lifecycle when none of them match', function (): void {
    $recorder = bindRecordingDumpsS3Client();

    $changes = (new S3DumpsBucket())->synchroniseConfiguration();

    $writes = collect($recorder->calls)->pluck('name')->all();

    expect($writes)->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketVersioning')
        ->toContain('PutBucketLifecycleConfiguration');

    $attributes = collect($changes)->pluck('attribute')->all();
    expect($attributes)->toContain('block-public-access.BlockPublicAcls')
        ->toContain('versioning')
        ->toContain('lifecycle');
});

it('expires only noncurrent dump versions — never the current backup', function (): void {
    $recorder = bindRecordingDumpsS3Client();

    (new S3DumpsBucket())->synchroniseConfiguration();

    $put = collect($recorder->calls)->firstWhere('name', 'PutBucketLifecycleConfiguration');

    $rule = $put['args']['LifecycleConfiguration']['Rules'][0];

    // Each run overwrites its key in place, so noncurrent versions are the
    // history — bounded to 35 days. An Expiration on current versions would
    // delete the latest backup after a quiet month; its absence is the point.
    expect($rule['Filter'])->toBe(['Prefix' => ''])
        ->and($rule['Status'])->toBe('Enabled')
        ->and($rule['NoncurrentVersionExpiration'])->toBe(['NoncurrentDays' => 35])
        ->and($rule['AbortIncompleteMultipartUpload'])->toBe(['DaysAfterInitiation' => 7])
        ->and($rule)->not->toHaveKey('Expiration');
});

it('refuses to apply the retention lifecycle to anything that is not a -dumps bucket', function (): void {
    // This schedules data for deletion — a naming refactor wiring it to any
    // other bucket must hard-fail the sync, never write, never silently skip.
    $recorder = bindRecordingDumpsS3Client();

    $rogue = new class() extends S3DumpsBucket
    {
        public function name(): string
        {
            return 'yolo-111111111111-testing-my-app-config';
        }
    };

    $lifecycle = new ReflectionMethod($rogue, 'reconcileDumpRetentionLifecycle');

    expect(fn (): array => $lifecycle->invoke($rogue, true))
        ->toThrow(IntegrityCheckException::class, 'yolo-111111111111-testing-my-app-config');

    expect(collect($recorder->calls)->pluck('name'))
        ->not->toContain('PutBucketLifecycleConfiguration');
});
