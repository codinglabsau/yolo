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

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('names the asset bucket per app + environment', function (): void {
    expect((new AssetBucket())->name())->toBe('yolo-111111111111-testing-my-app-assets');
});

it('derives the bucket ARN from the name', function (): void {
    expect((new AssetBucket())->arn())->toBe('arn:aws:s3:::yolo-111111111111-testing-my-app-assets');
});

it('tags the bucket with its name and app owner', function (): void {
    expect((new AssetBucket())->tags())->toBe(['Name' => 'yolo-111111111111-testing-my-app-assets', 'yolo:scope' => 'app', 'yolo:app' => 'my-app']);
});

it('enforces the bucket CORS configuration through sync', function (): void {
    expect(new AssetBucket())->toBeInstanceOf(SynchronisesConfiguration::class);
});

it('returns no change and writes nothing when the bucket already has no CORS', function (): void {
    $recorder = bindRecordingAssetS3Client(['GetBucketCors' => new Result([])]);

    expect((new AssetBucket())->synchroniseConfiguration())->toBe([]);
    expect($recorder->calls)->not->toContain('DeleteBucketCors');
});

it('returns a cors change and deletes the config when one is present', function (): void {
    $recorder = bindRecordingAssetS3Client([
        'GetBucketCors' => new Result(['CORSRules' => [['AllowedMethods' => ['GET'], 'AllowedOrigins' => ['*']]]]),
    ]);

    $changes = (new AssetBucket())->synchroniseConfiguration();

    expect($changes)->toHaveCount(1);
    expect($changes[0]->attribute)->toBe('cors');
    expect($changes[0]->to)->toBe('removed (owned by the distribution)');
    expect($recorder->calls)->toContain('DeleteBucketCors');
});

it('reports the cors removal without writing under apply:false', function (): void {
    $recorder = bindRecordingAssetS3Client([
        'GetBucketCors' => new Result(['CORSRules' => [['AllowedMethods' => ['GET'], 'AllowedOrigins' => ['*']]]]),
    ]);

    expect((new AssetBucket())->synchroniseConfiguration(apply: false))->toHaveCount(1);
    expect($recorder->calls)->not->toContain('DeleteBucketCors');
});
