<?php

use Aws\Result;
use Aws\Command;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Yaml\Yaml;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\PublishAppManifestStep;

function claimMissing(string $awsErrorCode = 'NoSuchKey'): S3Exception
{
    return new S3Exception($awsErrorCode, new Command('GetObject'), [
        'response' => new Response(404),
    ]);
}

function publishedClaim(array $services): string
{
    return Yaml::dump([
        'name' => 'my-app',
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'services' => $services,
    ], 10, 2);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['ivs'],
    ]);
});

it('reports the claim pending on a plan pass without writing', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => claimMissing(),
    ], $captured);

    $step = new PublishAppManifestStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and($step->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});

it('publishes the claim into the env config bucket on apply', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => claimMissing(),
        'PutObject' => new Result(),
    ], $captured);

    expect((new PublishAppManifestStep())([]))->toBe(StepResult::CREATED);

    $put = collect($captured)->firstWhere('name', 'PutObject');

    expect($put['args']['Bucket'])->toBe('yolo-111111111111-testing-config')
        ->and($put['args']['Key'])->toBe('apps/my-app.yml')
        ->and((string) $put['args']['Body'])->toBe(publishedClaim(['ivs']));
});

it('publishes the full environment block — name first, services pinned last', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'database' => 'my-app-db',
        'services' => ['ivs'],
        'tasks' => ['web' => ['cpu' => 512]],
    ]);

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => claimMissing(),
        'PutObject' => new Result(),
    ], $captured);

    expect((new PublishAppManifestStep())([]))->toBe(StepResult::CREATED);

    $claim = Yaml::parse((string) collect($captured)->firstWhere('name', 'PutObject')['args']['Body']);

    expect($claim['database'])->toBe('my-app-db')
        ->and($claim['tasks'])->toBe(['web' => ['cpu' => 512]])
        ->and(array_key_first($claim))->toBe('name')
        ->and(array_key_last($claim))->toBe('services')
        ->and($claim['services'])->toBe(['ivs']);
});

it('reconciles a stale claim and leaves a current one alone', function (): void {
    assertSyncStepReconciles(
        fn (): PublishAppManifestStep => new PublishAppManifestStep(),
        function (array &$captured): void {
            bindRoutedS3Client([
                'GetObject' => new Result(['Body' => publishedClaim(['ivs'])]),
            ], $captured);
        },
        function (array &$captured): void {
            bindRoutedS3Client([
                'GetObject' => new Result(['Body' => publishedClaim([])]),
                'PutObject' => new Result(),
            ], $captured);
        },
        'PutObject',
    );
});

it('survives a greenfield plan pass where the bucket itself does not exist yet', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => claimMissing('NoSuchBucket'),
    ], $captured);

    $step = new PublishAppManifestStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});

it('fails the write with instructions when the environment was never synced', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => claimMissing('NoSuchBucket'),
        'PutObject' => new S3Exception('NoSuchBucket', new Command('PutObject'), [
            'response' => new Response(404),
        ]),
    ], $captured);

    expect(fn (): StepResult => (new PublishAppManifestStep())([]))
        ->toThrow(RuntimeException::class, 'run `yolo sync:environment testing` first');
});
