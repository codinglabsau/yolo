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

it('is a SynchronisesConfiguration for versioning only — CORS, BPA and tags stay create-once', function (): void {
    writeManagedBucketManifest();

    // The Developer tier holds s3:DeleteObject on this bucket, so versioning is the
    // one attribute reconciled on every sync — it's the only recovery path from a
    // bad delete short of an Admin-tier backup restore. Everything else hands the
    // bucket over at birth: an app may legitimately change its own CORS or serve
    // public objects.
    expect(new S3Bucket())->toBeInstanceOf(SynchronisesConfiguration::class);
});

it('reconciles versioning back to Enabled on an existing bucket found drifted', function (): void {
    writeManagedBucketManifest();

    $recorder = bindRecordingAppBucketS3Client([
        'GetBucketVersioning' => new Result(['Status' => 'Suspended']),
        'PutBucketVersioning' => new Result(),
    ]);

    $changes = (new S3Bucket())->synchroniseConfiguration(apply: true);

    expect(array_column($recorder->captured, 'name'))->toContain('PutBucketVersioning');
    expect($changes)->toHaveCount(1);

    $put = collect($recorder->captured)->firstWhere('name', 'PutBucketVersioning');
    expect($put['args']['VersioningConfiguration']['Status'])->toBe('Enabled');
});

it('reports versioning drift but writes nothing on a dry run', function (): void {
    writeManagedBucketManifest();

    $recorder = bindRecordingAppBucketS3Client([
        'GetBucketVersioning' => new Result(['Status' => 'Suspended']),
    ]);

    $changes = (new S3Bucket())->synchroniseConfiguration(apply: false);

    expect($changes)->toHaveCount(1);
    expect(array_column($recorder->captured, 'name'))->not->toContain('PutBucketVersioning');
});

it('leaves an already-versioned bucket alone', function (): void {
    writeManagedBucketManifest();

    $recorder = bindRecordingAppBucketS3Client([
        'GetBucketVersioning' => new Result(['Status' => 'Enabled']),
    ]);

    expect((new S3Bucket())->synchroniseConfiguration(apply: true))->toBe([]);
    expect(array_column($recorder->captured, 'name'))->not->toContain('PutBucketVersioning');
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

it('stamps Block Public Access, CORS and versioning at create — and never tags the bucket', function (): void {
    writeManagedBucketManifest();

    $recorder = bindRecordingAppBucketS3Client([
        'HeadBucket' => new Result(['@metadata' => ['statusCode' => 200]]), // the BucketExists waiter
        'GetBucketVersioning' => new Result(), // no Status yet — a fresh bucket
        'PutBucketVersioning' => new Result(),
    ]);

    (new S3Bucket())->create();

    expect(array_column($recorder->captured, 'name'))
        ->toContain('CreateBucket')
        ->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketCors')
        ->toContain('PutBucketVersioning')
        ->not->toContain('PutBucketTagging');

    $put = collect($recorder->captured)->firstWhere('name', 'PutBucketCors');
    expect($put['args']['CORSConfiguration']['CORSRules'])->toBe(managedAppBucketCors());

    $versioning = collect($recorder->captured)->firstWhere('name', 'PutBucketVersioning');
    expect($versioning['args']['VersioningConfiguration']['Status'])->toBe('Enabled');
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

it('probes existence through ListBuckets, never HeadBucket — the read tiers hold no ListBucket on user data', function (): void {
    writeManagedBucketManifest();

    $recorder = bindRecordingAppBucketS3Client([
        'ListBuckets' => new Result(['Buckets' => [['Name' => 'yolo-111111111111-testing-my-app-data']]]),
    ]);

    // HeadBucket authorises on s3:ListBucket, which is deliberately absent on the
    // data bucket — a 403 there would read as "missing" and plan a create every sync.
    expect((new S3Bucket())->exists())->toBeTrue();
    expect(array_column($recorder->captured, 'name'))->toBe(['ListBuckets']);

    $recorder = bindRecordingAppBucketS3Client([
        'ListBuckets' => new Result(['Buckets' => [['Name' => 'someone-elses-bucket']]]),
    ]);

    expect((new S3Bucket())->exists())->toBeFalse();
});
