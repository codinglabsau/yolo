<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Runtime\Manifest;

function runtimeManifest(string $yaml): string
{
    $path = tempnam(sys_get_temp_dir(), 'yolo-runtime-manifest-');

    file_put_contents($path, $yaml);

    return $path;
}

it('has the service when any environment claims it', function (): void {
    $path = runtimeManifest(<<<'YAML'
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

    expect(Manifest::load($path)->hasService(Service::TYPESENSE))->toBeTrue();

    unlink($path);
});

it('does not have a service no environment claims', function (): void {
    $path = runtimeManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        services:
          - ivs
    YAML);

    expect(Manifest::load($path)->hasService(Service::TYPESENSE))->toBeFalse();

    unlink($path);
});

it('has no services without a services list', function (): void {
    $path = runtimeManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        bucket: true
    YAML);

    expect(Manifest::load($path)->hasService(Service::TYPESENSE))->toBeFalse();

    unlink($path);
});

it('loads a missing manifest as empty', function (): void {
    expect(Manifest::load('/nowhere/yolo.yml')->hasService(Service::TYPESENSE))->toBeFalse();
});

it('loads a malformed manifest as empty', function (): void {
    // The CLI parses the same file loudly on every build/sync — the runtime
    // read is a guest of the consuming app and must never break artisan.
    $path = runtimeManifest("environments:\n  production: [unclosed");

    expect(Manifest::load($path)->hasService(Service::TYPESENSE))->toBeFalse();

    unlink($path);
});

it('ignores a services key that is not a list', function (): void {
    $path = runtimeManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        services: typesense
    YAML);

    expect(Manifest::load($path)->hasService(Service::TYPESENSE))->toBeFalse();

    unlink($path);
});
