<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\PromiseInterface;

function bindS3WrapperClient(callable $handler): void
{
    $mock = new class($handler) extends MockHandler
    {
        /** @param callable $handler */
        public function __construct(protected $handler) {}

        public function __invoke($cmd, $request)
        {
            return ($this->handler)($cmd);
        }
    };

    Helpers::app()->instance('s3', new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

it('reads lifecycle rules back as null when none are configured', function (): void {
    // A bucket with no lifecycle config THROWS NoSuchLifecycleConfiguration —
    // it doesn't return an empty result — so the catch is the live code path
    // on every fresh logs bucket.
    bindS3WrapperClient(fn ($cmd): PromiseInterface => Create::rejectionFor(new S3Exception(
        'The lifecycle configuration does not exist',
        new Command('GetBucketLifecycleConfiguration'),
        ['code' => 'NoSuchLifecycleConfiguration'],
    )));

    expect(S3::lifecycleRules('yolo-111111111111-testing-logs'))->toBeNull();
});

it('rethrows lifecycle read failures that are not the missing-config case', function (): void {
    bindS3WrapperClient(fn ($cmd): PromiseInterface => Create::rejectionFor(new S3Exception(
        'Access Denied',
        new Command('GetBucketLifecycleConfiguration'),
        ['code' => 'AccessDenied'],
    )));

    S3::lifecycleRules('yolo-111111111111-testing-logs');
})->throws(S3Exception::class, 'Access Denied');

it('reads the bucket policy as null when the bucket itself does not exist yet', function (): void {
    // The plan pass can read the policy of a sibling bucket the apply pass
    // hasn't created yet (asset distribution → renamed asset bucket on a
    // migration's first sync) — that must read as "no policy", not crash
    // the whole plan.
    bindS3WrapperClient(fn ($cmd): PromiseInterface => Create::rejectionFor(new S3Exception(
        'The specified bucket does not exist',
        new Command('GetBucketPolicy'),
        ['code' => 'NoSuchBucket'],
    )));

    expect(S3::bucketPolicy('yolo-111111111111-testing-my-app-assets'))->toBeNull();
});

it('returns the rules when a lifecycle configuration exists', function (): void {
    $rules = [['ID' => 'expire-logs', 'Status' => 'Enabled']];

    bindS3WrapperClient(fn ($cmd): PromiseInterface => Create::promiseFor(new Result(['Rules' => $rules])));

    expect(S3::lifecycleRules('yolo-111111111111-testing-logs'))->toBe($rules);
});

function s3AccessDenied(): S3Exception
{
    return new S3Exception('denied', new Command('PutObject'), ['code' => 'AccessDenied', 'response' => new Response(403)]);
}

it('retries an AccessDenied write while a just-widened policy propagates', function (): void {
    $calls = 0;

    $result = S3::retryWhilePermissionsPropagate(function () use (&$calls): string {
        $calls++;

        if ($calls < 3) {
            throw s3AccessDenied();
        }

        return 'written';
    }, maxAttempts: 5, sleepSeconds: 0);

    expect($result)->toBe('written')
        ->and($calls)->toBe(3);
});

it('gives up on a real permission gap once the window closes', function (): void {
    $calls = 0;

    $run = function () use (&$calls): void {
        S3::retryWhilePermissionsPropagate(function () use (&$calls): string {
            $calls++;

            throw s3AccessDenied();
        }, maxAttempts: 3, sleepSeconds: 0);
    };

    expect($run)->toThrow(S3Exception::class);
    expect($calls)->toBe(3);
});

it('never retries a failure that propagation cannot explain', function (): void {
    $calls = 0;

    $run = function () use (&$calls): void {
        S3::retryWhilePermissionsPropagate(function () use (&$calls): string {
            $calls++;

            throw new S3Exception('gone', new Command('PutObject'), ['code' => 'NoSuchBucket', 'response' => new Response(404)]);
        }, maxAttempts: 5, sleepSeconds: 0);
    };

    expect($run)->toThrow(S3Exception::class);
    expect($calls)->toBe(1);
});
