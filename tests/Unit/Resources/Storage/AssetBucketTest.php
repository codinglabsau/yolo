<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Bind an S3 client whose calls are routed by command name and recorded so tests
 * can assert which writes fired. Returns the recorder for `$recorder->calls`.
 *
 * @param  array<string, Result>  $byCommand
 */
function bindRecordingAssetS3Client(array $byCommand): object
{
    $recorder = new class($byCommand) extends MockHandler
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct(public array $byCommand) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = $cmd->getName();

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

function matchingCorsRules(): array
{
    return [[
        'AllowedMethods' => ['GET', 'HEAD'],
        'AllowedOrigins' => ['*'],
        'AllowedHeaders' => ['*'],
        'MaxAgeSeconds' => 86400,
    ]];
}

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

it('names the asset bucket per app + environment', function () {
    expect((new AssetBucket())->name())->toBe('yolo-testing-my-app-assets');
});

it('derives the bucket ARN from the name', function () {
    expect((new AssetBucket())->arn())->toBe('arn:aws:s3:::yolo-testing-my-app-assets');
});

it('tags the bucket with its name and app owner', function () {
    expect((new AssetBucket())->tags())->toBe(['Name' => 'yolo-testing-my-app-assets', 'yolo:app' => 'my-app']);
});

it('reconciles a CORS configuration so the origin serves Access-Control-Allow-Origin', function () {
    expect(new AssetBucket())->toBeInstanceOf(SynchronisesConfiguration::class);
});

it('returns no change and writes nothing when the CORS rules already match', function () {
    $recorder = bindRecordingAssetS3Client(['GetBucketCors' => new Result(['CORSRules' => matchingCorsRules()])]);

    expect((new AssetBucket())->synchroniseConfiguration())->toBe([]);
    expect($recorder->calls)->not->toContain('PutBucketCors');
});

it('returns a cors change and puts the rules when they drift', function () {
    $recorder = bindRecordingAssetS3Client([
        'GetBucketCors' => new Result(['CORSRules' => [['AllowedMethods' => ['GET'], 'AllowedOrigins' => ['https://example.com']]]]),
    ]);

    $changes = (new AssetBucket())->synchroniseConfiguration();

    expect($changes)->toHaveCount(1);
    expect($changes[0]->attribute)->toBe('cors');
    expect($recorder->calls)->toContain('PutBucketCors');
});

it('computes the cors diff without writing under apply:false', function () {
    $recorder = bindRecordingAssetS3Client([
        'GetBucketCors' => new Result(['CORSRules' => [['AllowedMethods' => ['GET']]]]),
    ]);

    expect((new AssetBucket())->synchroniseConfiguration(apply: false))->toHaveCount(1);
    expect($recorder->calls)->not->toContain('PutBucketCors');
});
