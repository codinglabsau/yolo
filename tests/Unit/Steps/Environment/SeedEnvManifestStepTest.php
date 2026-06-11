<?php

use Aws\Result;
use Aws\Command;
use GuzzleHttp\Psr7\Response;
use Codinglabs\Yolo\EnvManifest;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SeedEnvManifestStep;

function envManifestMissing(): S3Exception
{
    return new S3Exception('Not Found', new Command('HeadObject'), [
        'response' => new Response(404),
    ]);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('never touches an existing manifest — the file is the operator\'s', function (): void {
    foreach ([['dry-run' => true], []] as $options) {
        $captured = [];
        bindRoutedS3Client([
            'HeadObject' => new Result(),
        ], $captured);

        $step = new SeedEnvManifestStep();

        expect($step($options))->toBe(StepResult::SYNCED)
            ->and($step->changes())->toBeEmpty();
        expect(array_column($captured, 'name'))->not->toContain('PutObject');
    }
});

it('reports the seed pending on a plan pass without writing', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'HeadObject' => envManifestMissing(),
    ], $captured);

    $step = new SeedEnvManifestStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and($step->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});

it('seeds the default manifest into the env config bucket on apply', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'HeadObject' => envManifestMissing(),
        'PutObject' => new Result(),
    ], $captured);

    expect((new SeedEnvManifestStep())([]))->toBe(StepResult::CREATED);

    $put = collect($captured)->firstWhere('name', 'PutObject');

    expect($put['args']['Bucket'])->toBe('yolo-111111111111-testing-config')
        ->and($put['args']['Key'])->toBe('yolo-environment-testing.yml')
        ->and((string) $put['args']['Body'])->toBe(EnvManifest::seedContents());
});

it('survives a greenfield plan pass where the bucket itself does not exist yet', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'HeadObject' => new S3Exception('NoSuchBucket', new Command('HeadObject'), [
            'response' => new Response(404),
        ]),
    ], $captured);

    $step = new SeedEnvManifestStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(array_column($captured, 'name'))->not->toContain('PutObject');
});
