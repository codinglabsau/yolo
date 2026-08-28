<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\S3\S3BackupsBucket;
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

it('is env-scoped and named yolo-{account-id}-{env}-backups', function (): void {
    $bucket = new S3BackupsBucket();

    expect($bucket->name())->toBe('yolo-111111111111-testing-backups')
        ->and($bucket->scope())->toBe(Scope::Env)
        // env-scoped → yolo:scope=env, no yolo:app owner tag
        ->and($bucket->tags())->toBe(['Name' => 'yolo-111111111111-testing-backups', 'yolo:scope' => 'env']);
});

it('is deliberately not Deletable — dumps outlive environment teardown', function (): void {
    // A database dump is the one artefact whose purpose is to survive the loss
    // of everything else; teardown must never sweep it up.
    expect(new S3BackupsBucket())->not->toBeInstanceOf(Deletable::class);
});

it('reconciles BPA + versioning + the retention lifecycle when none of them match', function (): void {
    $recorder = bindRecordingDumpsS3Client();

    $changes = (new S3BackupsBucket())->synchroniseConfiguration();

    $writes = collect($recorder->calls)->pluck('name')->all();

    expect($writes)->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketVersioning')
        ->toContain('PutBucketLifecycleConfiguration');

    $attributes = collect($changes)->pluck('attribute')->all();
    expect($attributes)->toContain('block-public-access.BlockPublicAcls')
        ->toContain('versioning')
        ->toContain('lifecycle');
});

it('expires dated dumps by lifecycle and sweeps versioning debris', function (): void {
    $recorder = bindRecordingDumpsS3Client();

    (new S3BackupsBucket())->synchroniseConfiguration();

    $put = collect($recorder->calls)->firstWhere('name', 'PutBucketLifecycleConfiguration');

    [$expire, $sweep] = $put['args']['LifecycleConfiguration']['Rules'];

    // Retention lives in lifecycle, visibly: dated dumps expire after 35
    // days. Versioning stays on purely as tamper armour, so the second rule
    // only cleans what expiry/overwrite leaves behind — noncurrent versions
    // and delete markers.
    expect($expire['Filter'])->toBe(['Prefix' => ''])
        ->and($expire['Status'])->toBe('Enabled')
        ->and($expire['Expiration'])->toBe(['Days' => 35])
        ->and($expire['AbortIncompleteMultipartUpload'])->toBe(['DaysAfterInitiation' => 7])
        ->and($sweep['Expiration'])->toBe(['ExpiredObjectDeleteMarker' => true])
        ->and($sweep['NoncurrentVersionExpiration'])->toBe(['NoncurrentDays' => 14]);
});

it('refuses to apply the retention lifecycle to anything that is not a -backups bucket', function (): void {
    // This schedules data for deletion — a naming refactor wiring it to any
    // other bucket must hard-fail the sync, never write, never silently skip.
    $recorder = bindRecordingDumpsS3Client();

    $rogue = new class() extends S3BackupsBucket
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
