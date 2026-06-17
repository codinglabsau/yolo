<?php

declare(strict_types=1);

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;

function writeServiceListManifest(string $yaml): void
{
    file_put_contents(BASE_PATH . '/yolo.yml', $yaml);
    Helpers::app()->instance('environment', 'testing');
}

it('rewrites an inline list to a block sequence, re-attaching a trailing comment', function (): void {
    writeServiceListManifest(<<<'YAML'
    name: my-app
    environments:
      testing:
        region: ap-southeast-2  # keep me
        services: [ivs]  # search + media
        tasks:
          web: {}
    YAML);

    expect(Manifest::setServiceList(['ivs', 'mediaconvert']))->toBeTrue();

    $raw = file_get_contents(BASE_PATH . '/yolo.yml');

    expect($raw)->toContain("    services:  # search + media\n      - ivs\n      - mediaconvert")
        ->not->toContain('[ivs')   // inline flow gone
        ->toContain('# keep me')   // sibling comment untouched
        ->toContain('web: {}');    // unrelated keys untouched
});

it('rewrites a block-style list in place, replacing the - item children', function (): void {
    writeServiceListManifest(<<<'YAML'
    name: my-app
    environments:
      testing:
        services:
          - ivs
          - typesense
        region: ap-southeast-2
    YAML);

    expect(Manifest::setServiceList(['ivs', 'typesense', 'mediaconvert']))->toBeTrue();

    $raw = file_get_contents(BASE_PATH . '/yolo.yml');

    expect($raw)->toContain("    services:\n      - ivs\n      - typesense\n      - mediaconvert")
        ->toContain('region: ap-southeast-2');     // sibling key preserved
});

it('inserts the services key as a block sequence under the env block when absent', function (): void {
    writeServiceListManifest(<<<'YAML'
    name: my-app
    environments:
      testing:
        region: ap-southeast-2
    YAML);

    expect(Manifest::setServiceList(['rekognition']))->toBeTrue();

    expect(file_get_contents(BASE_PATH . '/yolo.yml'))
        ->toContain("    services:\n      - rekognition");
});

it('preserves blank lines around the key when rewriting an inline list in place', function (): void {
    writeServiceListManifest(<<<'YAML'
    name: my-app

    environments:
      testing:
        region: ap-southeast-2

        services: [ivs]

        tasks:
          web: {}
    YAML);

    expect(Manifest::setServiceList(['ivs', 'typesense']))->toBeTrue();

    expect(file_get_contents(BASE_PATH . '/yolo.yml'))->toBe(<<<'YAML'
    name: my-app

    environments:
      testing:
        region: ap-southeast-2

        services:
          - ivs
          - typesense

        tasks:
          web: {}
    YAML);
});

it('preserves blank lines when inserting the key into the env block', function (): void {
    writeServiceListManifest(<<<'YAML'
    name: my-app

    environments:
      testing:
        region: ap-southeast-2

        tasks:
          web: {}
    YAML);

    expect(Manifest::setServiceList(['typesense']))->toBeTrue();

    expect(file_get_contents(BASE_PATH . '/yolo.yml'))->toBe(<<<'YAML'
    name: my-app

    environments:
      testing:
        services:
          - typesense
        region: ap-southeast-2

        tasks:
          web: {}
    YAML);
});

it('drops a service (disable) and removes the key entirely for an empty list', function (): void {
    writeServiceListManifest(<<<'YAML'
    name: my-app
    environments:
      testing:
        services: [ivs, mediaconvert]
        region: ap-southeast-2
    YAML);

    expect(Manifest::setServiceList(['ivs']))->toBeTrue();
    expect(file_get_contents(BASE_PATH . '/yolo.yml'))
        ->toContain("    services:\n      - ivs")
        ->not->toContain('mediaconvert');

    expect(Manifest::setServiceList([]))->toBeTrue();
    expect(file_get_contents(BASE_PATH . '/yolo.yml'))
        ->not->toContain('services:')
        ->toContain('region: ap-southeast-2');
});
