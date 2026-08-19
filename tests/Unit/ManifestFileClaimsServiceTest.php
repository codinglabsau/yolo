<?php

declare(strict_types=1);

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;

function claimsManifest(string $yaml): string
{
    $path = tempnam(sys_get_temp_dir(), 'yolo-claims-');

    file_put_contents($path, $yaml);

    return $path;
}

it('claims the service when any environment lists it', function (): void {
    $path = claimsManifest(<<<'YAML'
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

    expect(Manifest::fileClaimsService($path, Service::TYPESENSE))->toBeTrue();

    unlink($path);
});

it('does not claim a service no environment lists', function (): void {
    $path = claimsManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        services:
          - ivs
    YAML);

    expect(Manifest::fileClaimsService($path, Service::TYPESENSE))->toBeFalse();

    unlink($path);
});

it('does not claim anything without a services list', function (): void {
    $path = claimsManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        bucket: true
    YAML);

    expect(Manifest::fileClaimsService($path, Service::TYPESENSE))->toBeFalse();

    unlink($path);
});

it('reads a missing manifest as no claim', function (): void {
    expect(Manifest::fileClaimsService('/nowhere/yolo.yml', Service::TYPESENSE))->toBeFalse();
});

it('reads a malformed manifest as no claim', function (): void {
    // The CLI parses the same file loudly on every build/sync — the runtime
    // read is a guest of the consuming app and must never break artisan.
    $path = claimsManifest("environments:\n  production: [unclosed");

    expect(Manifest::fileClaimsService($path, Service::TYPESENSE))->toBeFalse();

    unlink($path);
});

it('ignores a services key that is not a list', function (): void {
    $path = claimsManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        services: typesense
    YAML);

    expect(Manifest::fileClaimsService($path, Service::TYPESENSE))->toBeFalse();

    unlink($path);
});
