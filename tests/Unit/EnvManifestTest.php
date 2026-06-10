<?php

use Aws\Result;
use Aws\Command;
use GuzzleHttp\Psr7\Response;
use Codinglabs\Yolo\EnvManifest;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

function envManifestNotFound(): S3Exception
{
    return new S3Exception('Not Found', new Command('GetObject'), [
        'response' => new Response(404),
    ]);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('reads the manifest fresh from the env config bucket and memoises it', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "domain: codinglabs.com.au\nservices: {}\n"]),
    ], $captured);

    expect(EnvManifest::get('domain'))->toBe('codinglabs.com.au')
        ->and(EnvManifest::has('services'))->toBeTrue()
        ->and(EnvManifest::get('services'))->toBe([]);

    // one S3 read for the whole run, aimed at the env config bucket
    $reads = collect($captured)->where('name', 'GetObject');
    expect($reads)->toHaveCount(1)
        ->and($reads->first()['args']['Bucket'])->toBe('yolo-111111111111-testing-config')
        ->and($reads->first()['args']['Key'])->toBe('yolo-env.yml');
});

it('treats a missing manifest as nothing declared', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => envManifestNotFound(),
    ], $captured);

    expect(EnvManifest::current())->toBe([])
        ->and(EnvManifest::get('domain', 'fallback'))->toBe('fallback');
});

it('hard-fails on unrecognised manifest keys', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "flavour: spicy\n"]),
    ], $captured);

    expect(fn (): array => EnvManifest::current())
        ->toThrow(IntegrityCheckException::class, 'flavour');
});

it('hard-fails when the manifest is not a YAML map', function (): void {
    expect(fn (): array => EnvManifest::parse('just a string'))
        ->toThrow(IntegrityCheckException::class, 'YAML map');
});

it('seeds contents that parse clean and pass validation', function (): void {
    expect(EnvManifest::parse(EnvManifest::seedContents()))->toBe(['services' => []]);
});

it('derives an env-suffixed gitignored local working copy path', function (): void {
    expect(EnvManifest::localPath())->toEndWith('yolo-env.testing.yml');
});
