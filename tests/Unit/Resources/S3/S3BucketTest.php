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

it('reconciles the bucket CORS through sync', function (): void {
    expect(new S3Bucket())->toBeInstanceOf(SynchronisesConfiguration::class);
});

it('applies the managed CORS ruleset when the bucket has none', function (): void {
    $recorder = bindRecordingAppBucketS3Client(['GetBucketCors' => new Result([])]);

    $changes = (new S3Bucket())->synchroniseConfiguration();

    expect($changes)->toHaveCount(1);
    expect($changes[0]->attribute)->toBe('cors');
    expect($changes[0]->from)->toBeNull();

    $put = collect($recorder->captured)->firstWhere('name', 'PutBucketCors');
    expect($put)->not->toBeNull();
    expect($put['args']['CORSConfiguration']['CORSRules'])->toBe(managedAppBucketCors());
});

it('reports the CORS drift without writing under apply:false', function (): void {
    $recorder = bindRecordingAppBucketS3Client(['GetBucketCors' => new Result([])]);

    expect((new S3Bucket())->synchroniseConfiguration(apply: false))->toHaveCount(1);
    expect(array_column($recorder->captured, 'name'))->not->toContain('PutBucketCors');
});

it('writes nothing when the live CORS already matches the managed ruleset', function (): void {
    // Guards the ExposeHeaders-omission gotcha: the desired ruleset must round-trip
    // through GetBucketCors with no phantom drift.
    $recorder = bindRecordingAppBucketS3Client([
        'GetBucketCors' => new Result(['CORSRules' => managedAppBucketCors()]),
    ]);

    expect((new S3Bucket())->synchroniseConfiguration())->toBe([]);
    expect(array_column($recorder->captured, 'name'))->not->toContain('PutBucketCors');
});

it('overwrites a Vapor-style CORS config with the managed ruleset', function (): void {
    // Vapor's default lacks HEAD and MaxAgeSeconds, so YOLO takes ownership.
    $recorder = bindRecordingAppBucketS3Client([
        'GetBucketCors' => new Result(['CORSRules' => [[
            'AllowedOrigins' => ['*'],
            'AllowedMethods' => ['GET', 'PUT'],
            'AllowedHeaders' => ['*'],
        ]]]),
    ]);

    $changes = (new S3Bucket())->synchroniseConfiguration();

    expect($changes)->toHaveCount(1);
    expect($changes[0]->from)->toBe('present');

    $put = collect($recorder->captured)->firstWhere('name', 'PutBucketCors');
    expect($put['args']['CORSConfiguration']['CORSRules'])->toBe(managedAppBucketCors());
});
