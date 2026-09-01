<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Resources\S3\S3Bucket;
use Codinglabs\Yolo\Resources\Undeletable;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Bind an S3 client whose calls are routed by command name and recorded (name +
 * args) so tests can assert which writes fired and with what body. Returns the
 * recorder for `$recorder->captured`.
 *
 * @param  array<string, Result>  $byCommand
 */
function bindRecordingAppBucketS3Client(array $byCommand): object
{
    $recorder = new class($byCommand) extends MockHandler
    {
        /** @var array<int, array{name: string, args: array<string, mixed>}> */
        public array $captured = [];

        public function __construct(public array $byCommand) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

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

/**
 * The CORS ruleset YOLO stamps on a bucket it creates — kept in lockstep with
 * S3Bucket::desiredCors().
 *
 * @return array<int, array<string, mixed>>
 */
function managedAppBucketCors(): array
{
    return [[
        'AllowedOrigins' => ['*'],
        'AllowedMethods' => ['GET', 'PUT', 'HEAD'],
        'AllowedHeaders' => ['*'],
        'MaxAgeSeconds' => 3600,
    ]];
}

/** Manifest with a bring-your-own bucket YOLO only ever adopts. */
function writeAdoptedBucketManifest(): void
{
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);
}

/** Manifest with a YOLO-named bucket (`bucket: true`). */
function writeManagedBucketManifest(): void
{
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => true,
    ]);
}

it('takes a bring-your-own bucket name from the manifest verbatim', function (): void {
    writeAdoptedBucketManifest();

    expect((new S3Bucket())->name())->toBe('my-app-bucket');
});

it('derives a YOLO-named bucket in the keyed namespace so it can never collide across environments', function (): void {
    writeManagedBucketManifest();

    // Account + environment + app: globally unique by construction, and inside the
    // yolo-* fence the admin tier may create and harden.
    expect((new S3Bucket())->name())->toBe('yolo-111111111111-testing-my-app-data');
});

it('is create-only — never a SynchronisesConfiguration, so an existing bucket is never reconciled', function (): void {
    writeManagedBucketManifest();

    // True in both modes: YOLO hands the bucket over at birth. It holds user data and
    // an app may legitimately change its own CORS or serve public objects.
    expect(new S3Bucket())->not->toBeInstanceOf(SynchronisesConfiguration::class);
});

it('is never deletable, so destroy:app leaves even a YOLO-named data bucket standing', function (): void {
    writeManagedBucketManifest();

    expect(new S3Bucket())->toBeInstanceOf(Undeletable::class);
});

it('is never YOLO-tagged in either mode — it stays out of the tag-based audit', function (): void {
    // Tagging a bucket YOLO stops managing at birth would claim it, and would leave a
    // permanent "unexpected" audit finding after a destroy:app that spared it.
    foreach ([writeAdoptedBucketManifest(...), writeManagedBucketManifest(...)] as $writeManifest) {
        $writeManifest();

        expect((new S3Bucket())->tags())->toBe([]);
        expect((new S3Bucket())->synchroniseTags(apply: true))->toBe([]);
        expect((new S3Bucket())->synchroniseTags(apply: false))->toBe([]);
    }
});

it('stamps Block Public Access and the CORS ruleset at create — and never tags the bucket', function (): void {
    writeManagedBucketManifest();

    $recorder = bindRecordingAppBucketS3Client([
        'HeadBucket' => new Result(['@metadata' => ['statusCode' => 200]]), // the BucketExists waiter
    ]);

    (new S3Bucket())->create();

    expect(array_column($recorder->captured, 'name'))
        ->toContain('CreateBucket')
        ->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketCors')
        ->not->toContain('PutBucketTagging');

    $put = collect($recorder->captured)->firstWhere('name', 'PutBucketCors');
    expect($put['args']['CORSConfiguration']['CORSRules'])->toBe(managedAppBucketCors());
});

it('creates the derived name, not the manifest value', function (): void {
    writeManagedBucketManifest();

    $recorder = bindRecordingAppBucketS3Client([
        'HeadBucket' => new Result(['@metadata' => ['statusCode' => 200]]),
    ]);

    (new S3Bucket())->create();

    $create = collect($recorder->captured)->firstWhere('name', 'CreateBucket');
    expect($create['args']['Bucket'])->toBe('yolo-111111111111-testing-my-app-data');
});
