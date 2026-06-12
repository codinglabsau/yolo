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
        ->and($reads->first()['args']['Key'])->toBe('yolo-environment-testing.yml');
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

it('points an unrecognised remote key at a YOLO upgrade — the bucket manifest may be newer than this binary', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "flavour: spicy\n"]),
    ], $captured);

    expect(fn (): array => EnvManifest::current())
        ->toThrow(IntegrityCheckException::class, 'newer YOLO release');
});

it('throws on a denied read rather than reporting the environment undeclared', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('Access Denied', new Command('GetObject'), [
            'code' => 'AccessDenied',
            'response' => new Response(403),
        ]),
    ], $captured);

    expect(fn (): array => EnvManifest::current())->toThrow(S3Exception::class);
});

it('throws on a denied head rather than reporting the manifest absent — the seed step must never overwrite on a misread', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'HeadObject' => new S3Exception('Access Denied', new Command('HeadObject'), [
            'code' => 'AccessDenied',
            'response' => new Response(403),
        ]),
    ], $captured);

    expect(fn (): bool => EnvManifest::remoteExists())->toThrow(S3Exception::class);
});

it('rejects the app-side list shape under services — same key, opposite shapes', function (): void {
    expect(fn (): array => EnvManifest::parse("services:\n  - ivs\n"))
        ->toThrow(IntegrityCheckException::class, 'must be a map');
});

it('hard-fails when the manifest is not a YAML map', function (): void {
    expect(fn (): array => EnvManifest::parse('just a string'))
        ->toThrow(IntegrityCheckException::class, 'YAML map');
});

it('seeds contents that parse clean and pass validation', function (): void {
    expect(EnvManifest::parse(EnvManifest::seedContents()))->toBe(['services' => []]);
});

it('names the manifest after its environment, in the bucket and on disk', function (): void {
    expect(EnvManifest::filename())->toBe('yolo-environment-testing.yml')
        ->and(EnvManifest::localPath())->toEndWith('/yolo-environment-testing.yml');
});

it('accepts a declared ivs service', function (): void {
    expect(EnvManifest::parse("services:\n  ivs: {}\n"))->toBe(['services' => ['ivs' => []]]);
});

it('rejects a scalar offer — the allow-list cannot catch a leaf, the definition validates the shape', function (): void {
    expect(fn (): array => EnvManifest::parse("services:\n  ivs: true\n"))
        ->toThrow(IntegrityCheckException::class, 'services.ivs');
});

it('rejects unknown keys inside an offer block via the per-service offer keys', function (): void {
    expect(fn (): array => EnvManifest::parse("services:\n  ivs:\n    nodes: 3\n"))
        ->toThrow(IntegrityCheckException::class, 'services.ivs.nodes');
});
