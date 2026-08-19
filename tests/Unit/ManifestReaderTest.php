<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\ManifestReader;

function readerManifest(string $yaml): string
{
    $path = tempnam(sys_get_temp_dir(), 'yolo-manifest-reader-');

    file_put_contents($path, $yaml);

    return $path;
}

it('reads within its environment block', function (): void {
    $path = readerManifest(<<<'YAML'
    name: my-app
    environments:
      staging:
        bucket: true
        tasks:
          web:
            cpu: 512
      production:
        bucket: my-bucket
    YAML);

    $reader = ManifestReader::load($path, 'staging');

    expect($reader->has('bucket'))->toBeTrue()
        ->and($reader->get('bucket'))->toBeTrue()
        ->and($reader->get('tasks.web.cpu'))->toBe(512)
        ->and($reader->has('domain'))->toBeFalse()
        ->and($reader->get('domain', 'fallback'))->toBe('fallback');
});

it('reads nothing for an environment the manifest does not declare', function (): void {
    $path = readerManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        bucket: true
    YAML);

    $reader = ManifestReader::load($path, 'local');

    expect($reader->has('bucket'))->toBeFalse()
        ->and($reader->get('bucket', 'fallback'))->toBe('fallback')
        ->and($reader->services())->toBe([]);
});

it('lists its environment services', function (): void {
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

    expect(ManifestReader::load($path, 'staging')->services())->toBe(['ivs'])
        ->and(ManifestReader::load($path, 'production')->services())->toBe(['ivs', 'typesense']);
});

it('has the service when any environment claims it, regardless of its own', function (): void {
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

    $reader = ManifestReader::load($path, 'local');

    expect($reader->hasService(Service::TYPESENSE))->toBeTrue()
        ->and($reader->hasService(Service::MEDIA_CONVERT))->toBeFalse();
});

it('ignores a services key that is not a list', function (): void {
    $path = readerManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        services: typesense
    YAML);

    $reader = ManifestReader::load($path, 'production');

    expect($reader->services())->toBe([])
        ->and($reader->hasService(Service::TYPESENSE))->toBeFalse();
});

it('loads a missing manifest as empty', function (): void {
    $reader = ManifestReader::load('/nowhere/yolo.yml', 'production');

    expect($reader->hasService(Service::TYPESENSE))->toBeFalse()
        ->and($reader->has('bucket'))->toBeFalse();
});

it('loads a malformed manifest as empty', function (): void {
    // The CLI parses the same file loudly on every build/sync — the runtime
    // read is a guest of the consuming app and must never break artisan.
    $path = readerManifest("environments:\n  production: [unclosed");

    expect(ManifestReader::load($path, 'production')->hasService(Service::TYPESENSE))->toBeFalse();
});
