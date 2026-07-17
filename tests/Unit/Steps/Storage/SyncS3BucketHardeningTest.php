<?php

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncS3BucketStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncS3ConfigBucketStep;

/**
 * Bind a mock S3 client with command-routed responses. A command's value may be a
 * single Result/Throwable (repeated) or an array used as a queue (last entry
 * repeats). A Throwable entry is returned as a rejected promise — used to make
 * HeadBucket 404 so doesBucketExistV2() reports the bucket missing. Calls are
 * captured by reference.
 *
 * @param  array<string, Result|Throwable|array<int, Result|Throwable>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindMockS3Client(array $byCommand, array &$captured): void
{
    $mock = new class($byCommand, $captured) extends MockHandler
    {
        /** @var array<string, int> */
        private array $cursors = [];

        public function __construct(protected array $byCommand, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $name = $cmd->getName();
            $this->captured[] = ['name' => $name, 'args' => $cmd->toArray()];

            $entry = $this->byCommand[$name] ?? new Result();

            if (is_array($entry)) {
                $index = min($this->cursors[$name] ?? 0, count($entry) - 1);
                $this->cursors[$name] = $index + 1;
                $entry = $entry[$index];
            }

            return $entry instanceof Throwable
                ? Create::rejectionFor($entry)
                : Create::promiseFor($entry);
        }
    };

    Helpers::app()->instance('s3', new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

function s3NotFound(): S3Exception
{
    return new S3Exception('Not Found', new Command('HeadBucket'), [
        'response' => new Response(404),
    ]);
}

function s3Forbidden(): S3Exception
{
    return new S3Exception('Forbidden', new Command('HeadBucket'), [
        'response' => new Response(403),
    ]);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('locks down and versions a newly created config bucket', function (): void {
    $captured = [];

    bindMockS3Client([
        // missing on the first check, then present for the BucketExists waiter
        // (which matches on a 200 status).
        'HeadBucket' => [s3NotFound(), new Result(['@metadata' => ['statusCode' => 200]])],
        'CreateBucket' => new Result(),
        'PutBucketTagging' => new Result(),
        'PutPublicAccessBlock' => new Result(),
        'PutBucketVersioning' => new Result(),
    ], $captured);

    expect((new SyncS3ConfigBucketStep())([]))->toBe(StepResult::CREATED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('CreateBucket')
        ->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketVersioning');

    $blockPublicAccess = collect($captured)->firstWhere('name', 'PutPublicAccessBlock');
    expect($blockPublicAccess['args']['PublicAccessBlockConfiguration'])->toBe([
        'BlockPublicAcls' => true,
        'IgnorePublicAcls' => true,
        'BlockPublicPolicy' => true,
        'RestrictPublicBuckets' => true,
    ]);

    $versioning = collect($captured)->firstWhere('name', 'PutBucketVersioning');
    expect($versioning['args']['VersioningConfiguration']['Status'])->toBe('Enabled');
});

it('reconciles BPA, versioning and the yolo:app tag onto an existing config bucket', function (): void {
    $captured = [];

    bindMockS3Client([
        'HeadBucket' => new Result(),   // exists
        // The yolo:scope marker is present (this IS our bucket), but the later
        // yolo:app owner tag is missing — the additive sync backfills it.
        'GetBucketTagging' => new Result(['TagSet' => [
            ['Key' => 'Name', 'Value' => 'yolo-111111111111-testing-my-app-config'],
            ['Key' => 'yolo:scope', 'Value' => 'app'],
        ]]),
        'PutPublicAccessBlock' => new Result(),
        'PutBucketVersioning' => new Result(),
        'PutBucketTagging' => new Result(),
    ], $captured);

    expect((new SyncS3ConfigBucketStep())([]))->toBe(StepResult::SYNCED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('PutPublicAccessBlock')
        ->toContain('PutBucketVersioning')
        ->toContain('PutBucketTagging')   // older buckets backfill yolo:app on sync
        ->not->toContain('CreateBucket');

    // The step predates the create-or-sync Resource pattern, so this is the
    // explicit tag sync that lets `yolo audit` attribute the bucket to its app.
    $tagging = collect($captured)->firstWhere('name', 'PutBucketTagging');
    $tags = collect($tagging['args']['Tagging']['TagSet'])
        ->mapWithKeys(fn (array $tag): array => [$tag['Key'] => $tag['Value']]);

    expect($tags['yolo:app'])->toBe('my-app');
});

it('reports drift but does not mutate the config bucket during a dry-run', function (): void {
    $captured = [];

    bindMockS3Client([
        'HeadBucket' => new Result(),   // exists
        'GetBucketTagging' => new Result(['TagSet' => [
            ['Key' => 'Name', 'Value' => 'yolo-111111111111-testing-my-app-config'],
            ['Key' => 'yolo:scope', 'Value' => 'app'],
        ]]),
    ], $captured);

    // The live config reads back empty here, so a dry-run sees drift (WOULD_SYNC)
    // but writes nothing.
    expect((new SyncS3ConfigBucketStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);

    $names = array_column($captured, 'name');
    expect($names)->not->toContain('PutPublicAccessBlock')
        ->not->toContain('PutBucketVersioning')
        ->not->toContain('PutBucketPolicy');
});

it('blocks public access on a newly created app bucket', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    $captured = [];

    bindMockS3Client([
        'HeadBucket' => [s3NotFound(), new Result(['@metadata' => ['statusCode' => 200]])],
        'CreateBucket' => new Result(),
        'PutBucketTagging' => new Result(),
        'PutPublicAccessBlock' => new Result(),
    ], $captured);

    expect((new SyncS3BucketStep())([]))->toBe(StepResult::CREATED);
    expect(array_column($captured, 'name'))->toContain('PutPublicAccessBlock');
});

it('does not flip public access on an existing app bucket', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    $captured = [];

    bindMockS3Client([
        'HeadBucket' => new Result(),   // exists
    ], $captured);

    expect((new SyncS3BucketStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain('PutPublicAccessBlock');
});

it('applies the browser-upload CORS to a newly created app bucket', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    $captured = [];

    bindMockS3Client([
        'HeadBucket' => [s3NotFound(), new Result(['@metadata' => ['statusCode' => 200]])],
        'CreateBucket' => new Result(),
        'PutBucketTagging' => new Result(),
        'PutPublicAccessBlock' => new Result(),
        'PutBucketCors' => new Result(),
    ], $captured);

    expect((new SyncS3BucketStep())([]))->toBe(StepResult::CREATED);
    expect(array_column($captured, 'name'))
        ->toContain('PutPublicAccessBlock')   // BPA still applied at create
        ->toContain('PutBucketCors');
});

it('leaves an existing app bucket completely untouched — create-only, never reconciled', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    $captured = [];

    bindMockS3Client([
        'HeadBucket' => new Result(),   // exists
    ], $captured);

    expect((new SyncS3BucketStep())([]))->toBe(StepResult::SYNCED);

    // Reference-only after create: no attribute is read or written on an existing
    // bucket — not CORS, not tags, not BPA — so the tier needs no S3 perm on it.
    expect(array_column($captured, 'name'))
        ->not->toContain('GetBucketCors')
        ->not->toContain('PutBucketCors')
        ->not->toContain('GetBucketTagging')
        ->not->toContain('PutBucketTagging')
        ->not->toContain('PutPublicAccessBlock');
});

it('treats a 403 on the existence check as an unowned bucket and leaves it alone', function (): void {
    // The capped admin tier has no S3 perms on a custom-named (non yolo-*) bucket,
    // so HeadBucket comes back 403 — which means "exists, not ours". The step must
    // skip it cleanly, never hard-fail the sync (this is the real codinglabsio case).
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'codinglabsio',
    ]);

    $captured = [];

    bindMockS3Client([
        'HeadBucket' => s3Forbidden(),
    ], $captured);

    expect((new SyncS3BucketStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))
        ->not->toContain('CreateBucket')
        ->not->toContain('PutBucketCors')
        ->not->toContain('PutPublicAccessBlock');
});

it('never puts a bucket policy on the config bucket (log-delivery belongs to S3LogsBucket)', function (): void {
    // The config bucket holds secrets and must carry no external principals
    // — the ELB log-delivery grant is env-scoped logic that belongs on the
    // env logs bucket (S3LogsBucket), and putting it here would also collide
    // with the account → environment → app sync order. A synced config
    // bucket must NEVER call PutBucketPolicy.
    $captured = [];

    bindMockS3Client([
        'HeadBucket' => [s3NotFound(), new Result(['@metadata' => ['statusCode' => 200]])],
        'CreateBucket' => new Result(),
        'PutBucketTagging' => new Result(),
        'PutPublicAccessBlock' => new Result(),
        'PutBucketVersioning' => new Result(),
    ], $captured);

    expect((new SyncS3ConfigBucketStep())([]))->toBe(StepResult::CREATED);
    expect(array_column($captured, 'name'))->not->toContain('PutBucketPolicy');
});
