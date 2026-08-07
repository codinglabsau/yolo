<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Commands\SyncAppCommand;
use Symfony\Component\Console\Output\BufferedOutput;

function invokeAppBucketAdoptable(): bool
{
    $command = new SyncAppCommand();
    $method = new ReflectionMethod($command, 'ensureAppBucketAdoptable');

    return $method->invoke($command);
}

/**
 * Bind an S3 client answering ListBuckets with $owned, and HeadBucket according to
 * $headStatus (404 for a free name, 403 for one owned by another account).
 *
 * @param  array<int, string>  $owned
 */
function bindOwnershipS3Client(array $owned, int $headStatus = 404): void
{
    $mock = new class($owned, $headStatus) extends MockHandler
    {
        /** @param array<int, string> $owned */
        public function __construct(protected array $owned, protected int $headStatus) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            if ($cmd->getName() === 'ListBuckets') {
                return Create::promiseFor(new Result([
                    'Buckets' => array_map(fn (string $name): array => ['Name' => $name], $this->owned),
                ]));
            }

            return Create::rejectionFor(new S3Exception('denied', $cmd, [
                'response' => new Response($this->headStatus),
                'code' => $this->headStatus === 404 ? 'NoSuchBucket' : 'AccessDenied',
            ]));
        }
    };

    Helpers::app()->instance('s3', new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

beforeEach(function (): void {
    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

it('passes when no app bucket is declared', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    bindOwnershipS3Client([]);

    expect(invokeAppBucketAdoptable())->toBeTrue();
});

it('passes without any lookup when YOLO owns the bucket', function (): void {
    // `bucket: true` names it in YOLO's namespace and the sync creates it, so there is
    // no adoption to verify.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => true,
    ]);

    bindOwnershipS3Client([]);

    expect(invokeAppBucketAdoptable())->toBeTrue();
});

it('adopts a bring-your-own bucket that exists on this account', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    bindOwnershipS3Client(['unrelated-bucket', 'my-app-bucket']);

    expect(invokeAppBucketAdoptable())->toBeTrue();
});

it('refuses a bucket that does not exist, and recommends the YOLO-owned form', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    bindOwnershipS3Client(['unrelated-bucket'], headStatus: 404);

    expect(invokeAppBucketAdoptable())->toBeFalse();

    $output = test()->promptOutput->fetch();

    expect($output)->toContain('my-app-bucket')
        ->toContain('bucket: true')
        ->toContain('yolo-111111111111-testing-my-app-data')
        ->not->toContain('another AWS account');   // a free name isn't a collision
});

it('refuses a bucket owned by another AWS account instead of silently adopting it', function (): void {
    // The hazard this gate exists for: S3's namespace is global, so a name taken by
    // someone else answers HeadBucket with a 403 that is indistinguishable from
    // "yours, unreadable by this tier". Adopting it syncs clean, grants the task role
    // an ARN in a foreign account, and then fails every runtime write.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    bindOwnershipS3Client(['unrelated-bucket'], headStatus: 403);

    expect(invokeAppBucketAdoptable())->toBeFalse();

    expect(test()->promptOutput->fetch())
        ->toContain('taken in another AWS account')
        ->toContain('bucket: true');
});

it('applies the same gate to the orchestrating sync, which composes scopes but not handle()', function (): void {
    // Without this, `yolo sync <env>` would reach a CreateBucket it has no permission
    // for, mid-apply — the failure this gate exists to pre-empt.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    bindOwnershipS3Client(['unrelated-bucket']);

    $command = new SyncCommand();
    $method = new ReflectionMethod($command, 'ensureAppBucketAdoptable');

    expect($method->invoke($command))->toBeFalse();
});
