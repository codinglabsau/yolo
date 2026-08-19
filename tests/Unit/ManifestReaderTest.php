<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\ManifestReader;

function readerManifest(string $yaml): string
{
    $path = tempnam(sys_get_temp_dir(), 'yolo-runtime-manifest-');

    file_put_contents($path, $yaml);

    return $path;
}

it('has the service when any environment claims it', function (): void {
    $path = readerManifest(<<<'YAML'
    name: my-app
    environments:
      staging:
        services:
          - ivs
      production:
        services:
          - ivs
          - typesense
    YAML);

    expect(ManifestReader::load($path)->hasService(Service::TYPESENSE))->toBeTrue();

    unlink($path);
});

it('scopes the claim to one environment when given', function (): void {
    $path = readerManifest(<<<'YAML'
    name: my-app
    environments:
      staging:
        services:
          - ivs
      production:
        services:
          - typesense
    YAML);

    $reader = ManifestReader::load($path);

    expect($reader->hasService(Service::TYPESENSE, 'production'))->toBeTrue()
        ->and($reader->hasService(Service::TYPESENSE, 'staging'))->toBeFalse()
        ->and($reader->services('staging'))->toBe(['ivs'])
        ->and($reader->services('missing'))->toBe([]);

    unlink($path);
});

it('does not have a service no environment claims', function (): void {
    $path = readerManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        services:
          - ivs
    YAML);

    expect(ManifestReader::load($path)->hasService(Service::TYPESENSE))->toBeFalse();

    unlink($path);
});

it('has no services without a services list', function (): void {
    $path = readerManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        bucket: true
    YAML);

    expect(ManifestReader::load($path)->hasService(Service::TYPESENSE))->toBeFalse();

    unlink($path);
});

it('loads a missing manifest as empty', function (): void {
    expect(ManifestReader::load('/nowhere/yolo.yml')->hasService(Service::TYPESENSE))->toBeFalse();
});

it('loads a malformed manifest as empty', function (): void {
    // The CLI parses the same file loudly on every build/sync — the runtime
    // read is a guest of the consuming app and must never break artisan.
    $path = readerManifest("environments:\n  production: [unclosed");

    expect(ManifestReader::load($path)->hasService(Service::TYPESENSE))->toBeFalse();

    unlink($path);
});

it('ignores a services key that is not a list', function (): void {
    $path = readerManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        services: typesense
    YAML);

    expect(ManifestReader::load($path)->hasService(Service::TYPESENSE))->toBeFalse();

    unlink($path);
});
