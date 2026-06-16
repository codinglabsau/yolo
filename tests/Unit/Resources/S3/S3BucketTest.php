<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Resources\S3\S3Bucket;
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
 * The managed CORS ruleset YOLO reconciles onto the app bucket — kept in lockstep
 * with S3Bucket::desiredCors().
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

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);
});

it('names the app bucket from the manifest bucket key', function (): void {
    expect((new S3Bucket())->name())->toBe('my-app-bucket');
});

it('is create-only — never a SynchronisesConfiguration, so an existing bucket is never reconciled', function (): void {
    expect(new S3Bucket())->not->toBeInstanceOf(SynchronisesConfiguration::class);
});

it('is never YOLO-tagged — client-managed, so it stays out of the tag-based audit', function (): void {
    // No ownership tag is ever produced or applied: tags() is empty and the sync
    // path reconciles nothing.
    expect((new S3Bucket())->tags())->toBe([]);
    expect((new S3Bucket())->synchroniseTags(apply: true))->toBe([]);
    expect((new S3Bucket())->synchroniseTags(apply: false))->toBe([]);
});

it('stamps Block Public Access and the managed CORS ruleset at create — and never tags the bucket', function (): void {
    $recorder = bindRecordingAppBucketS3Client([
        'HeadBucket' => new Result(['@metadata' => ['statusCode' => 200]]), // the BucketExists waiter
    ]);

    (new S3Bucket())->create();

    expect(array_column($recorder->captured, 'name'))
        ->toContain('CreateBucket')
        ->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketCors')
        ->not->toContain('PutBucketTagging');   // client-managed → never tagged

    $put = collect($recorder->captured)->firstWhere('name', 'PutBucketCors');
    expect($put['args']['CORSConfiguration']['CORSRules'])->toBe(managedAppBucketCors());
});
