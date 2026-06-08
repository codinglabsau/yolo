<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\S3\S3LoadBalancerLogs;

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

it('is env-scoped and named yolo-{env}-alb-logs', function (): void {
    $bucket = new S3LoadBalancerLogs();

    expect($bucket->name())->toBe('yolo-testing-alb-logs')
        ->and($bucket->scope())->toBe(Scope::Env)
        // env-scoped → yolo:scope=env, no yolo:app owner tag
        ->and($bucket->tags())->toBe(['Name' => 'yolo-testing-alb-logs', 'yolo:scope' => 'env']);
});

it('reconciles BPA + versioning + the log-delivery policy when none of them match', function (): void {
    $recorder = bindRecordingS3Client();

    $changes = (new S3LoadBalancerLogs())->synchroniseConfiguration();

    $writes = collect($recorder->calls)->pluck('name')->all();

    expect($writes)->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketVersioning')
        ->toContain('PutBucketPolicy');

    // every drifted attribute surfaces as a Change
    $attributes = collect($changes)->pluck('attribute')->all();
    expect($attributes)->toContain('block-public-access.BlockPublicAcls')
        ->toContain('versioning')
        ->toContain('bucket-policy');
});

it('grants ELB access-log delivery to the log-delivery service principal over the whole bucket', function (): void {
    $recorder = bindRecordingS3Client();

    (new S3LoadBalancerLogs())->synchroniseConfiguration();

    $put = collect($recorder->calls)->firstWhere('name', 'PutBucketPolicy');

    $statement = json_decode((string) $put['args']['Policy'], true)['Statement'][0];

    expect($statement['Effect'])->toBe('Allow')
        ->and($statement['Principal'])->toBe(['Service' => 'logdelivery.elasticloadbalancing.amazonaws.com'])
        ->and($statement['Action'])->toBe('s3:PutObject')
        // dedicated bucket → /* is the full grant, no prefix scoping needed
        ->and($statement['Resource'])->toBe('arn:aws:s3:::yolo-testing-alb-logs/*')
        ->and($statement['Condition']['StringEquals']['aws:SourceAccount'])->toBe('111111111111')
        ->and($statement['Condition']['ArnLike']['aws:SourceArn'])
        ->toBe('arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/*');
});

it('computes the diff without writing under apply:false', function (): void {
    $recorder = bindRecordingS3Client();

    $changes = (new S3LoadBalancerLogs())->synchroniseConfiguration(apply: false);

    // changes are recorded
    expect($changes)->not->toBeEmpty();

    // but nothing was written
    $writes = collect($recorder->calls)->pluck('name')->all();
    expect($writes)->not->toContain('PutPublicAccessBlock')
        ->not->toContain('PutBucketVersioning')
        ->not->toContain('PutBucketPolicy');
});

it('does not rewrite a policy that already matches', function (): void {
    // Bind a recorder whose GetBucketPolicy returns the exact desired document,
    // so the diff sees no drift and the apply pass skips the write.
    $resource = new S3LoadBalancerLogs();
    $desired = (new ReflectionMethod($resource, 'accessLogDeliveryPolicy'))->invoke($resource);

    $recorder = bindRecordingS3Client([
        'GetBucketPolicy' => new Result(['Policy' => json_encode($desired)]),
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
        ->not->toContain('PutBucketVersioning');
});
