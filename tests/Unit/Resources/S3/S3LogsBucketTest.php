<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\S3\S3LogsBucket;

/**
 * Bind an S3 client that records every command and returns the given responses
 * (looked up by command name; missing entries default to an empty Result).
 * Returns a recorder with a `calls` array of `['name', 'args']` entries.
 *
 * @param  array<string, Result>  $byCommand
 */
function bindRecordingS3Client(array $byCommand = []): object
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

it('is env-scoped and named yolo-{account-id}-{env}', function (): void {
    $bucket = new S3LogsBucket();

    expect($bucket->name())->toBe('yolo-111111111111-testing-logs')
        ->and($bucket->scope())->toBe(Scope::Env)
        // env-scoped → yolo:scope=env, no yolo:app owner tag
        ->and($bucket->tags())->toBe(['Name' => 'yolo-111111111111-testing-logs', 'yolo:scope' => 'env']);
});

it('reconciles BPA + versioning + the log-delivery policy + the lifecycle when none of them match', function (): void {
    $recorder = bindRecordingS3Client();

    $changes = (new S3LogsBucket())->synchroniseConfiguration();

    $writes = collect($recorder->calls)->pluck('name')->all();

    expect($writes)->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketVersioning')
        ->toContain('PutBucketPolicy')
        ->toContain('PutBucketLifecycleConfiguration');

    // every drifted attribute surfaces as a Change
    $attributes = collect($changes)->pluck('attribute')->all();
    expect($attributes)->toContain('block-public-access.BlockPublicAcls')
        ->toContain('versioning')
        ->toContain('bucket-policy')
        ->toContain('lifecycle');
});

it('grants ELB access-log delivery to the log-delivery service principal over the alb/ prefix only', function (): void {
    $recorder = bindRecordingS3Client();

    (new S3LogsBucket())->synchroniseConfiguration();

    $put = collect($recorder->calls)->firstWhere('name', 'PutBucketPolicy');

    $statement = json_decode((string) $put['args']['Policy'], true)['Statement'][0];

    expect($statement['Effect'])->toBe('Allow')
        ->and($statement['Principal'])->toBe(['Service' => 'logdelivery.elasticloadbalancing.amazonaws.com'])
        ->and($statement['Action'])->toBe('s3:PutObject')
        // shared bucket → the delivery principal can never write outside alb/
        ->and($statement['Resource'])->toBe('arn:aws:s3:::yolo-111111111111-testing-logs/alb/*')
        ->and($statement['Condition']['StringEquals']['aws:SourceAccount'])->toBe('111111111111')
        ->and($statement['Condition']['ArnLike']['aws:SourceArn'])
        ->toBe('arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/*');
});

it('expires the whole bucket after 90 days', function (): void {
    $recorder = bindRecordingS3Client();

    (new S3LogsBucket())->synchroniseConfiguration();

    $put = collect($recorder->calls)->firstWhere('name', 'PutBucketLifecycleConfiguration');

    $rule = $put['args']['LifecycleConfiguration']['Rules'][0];

    // bucket-wide — only telemetry lives here, so any future log class
    // inherits expiry by default
    expect($rule['Filter'])->toBe(['Prefix' => ''])
        ->and($rule['Status'])->toBe('Enabled')
        ->and($rule['Expiration'])->toBe(['Days' => 90])
        ->and($rule['NoncurrentVersionExpiration'])->toBe(['NoncurrentDays' => 7])
        ->and($rule['AbortIncompleteMultipartUpload'])->toBe(['DaysAfterInitiation' => 7]);
});

it('computes the diff without writing under apply:false', function (): void {
    $recorder = bindRecordingS3Client();

    $changes = (new S3LogsBucket())->synchroniseConfiguration(apply: false);

    // changes are recorded
    expect($changes)->not->toBeEmpty();

    // but nothing was written
    $writes = collect($recorder->calls)->pluck('name')->all();
    expect($writes)->not->toContain('PutPublicAccessBlock')
        ->not->toContain('PutBucketVersioning')
        ->not->toContain('PutBucketPolicy')
        ->not->toContain('PutBucketLifecycleConfiguration');
});

it('does not rewrite a policy or lifecycle that already matches', function (): void {
    // Bind a recorder whose reads return the exact desired state, so the diff
    // sees no drift and the apply pass skips every write.
    $resource = new S3LogsBucket();
    $desired = (new ReflectionMethod($resource, 'accessLogDeliveryPolicy'))->invoke($resource);

    $recorder = bindRecordingS3Client([
        'GetBucketPolicy' => new Result(['Policy' => json_encode($desired)]),
        'GetBucketLifecycleConfiguration' => new Result(['Rules' => [
            [
                'ID' => 'expire-logs',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'Expiration' => ['Days' => 90],
                'NoncurrentVersionExpiration' => ['NoncurrentDays' => 7],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 7],
            ],
        ]]),
        // also report PBA + versioning already in the desired state so the whole
        // sync is a no-op rather than just the policy
        'GetPublicAccessBlock' => new Result(['PublicAccessBlockConfiguration' => [
            'BlockPublicAcls' => true,
            'IgnorePublicAcls' => true,
            'BlockPublicPolicy' => true,
            'RestrictPublicBuckets' => true,
        ]]),
        'GetBucketVersioning' => new Result(['Status' => 'Enabled']),
    ]);

    expect($resource->synchroniseConfiguration())->toBe([]);

    expect(collect($recorder->calls)->pluck('name'))
        ->not->toContain('PutBucketPolicy')
        ->not->toContain('PutPublicAccessBlock')
        ->not->toContain('PutBucketVersioning')
        ->not->toContain('PutBucketLifecycleConfiguration');
});
