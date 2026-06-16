<?php

declare(strict_types=1);

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;

function writeServiceListManifest(string $yaml): void
{
    file_put_contents(BASE_PATH . '/yolo.yml', $yaml);
    Helpers::app()->instance('environment', 'testing');
}

it('adds a service to an inline list, preserving comments and the rest of the file', function (): void {
    writeServiceListManifest(<<<'YAML'
    name: my-app
    environments:
      testing:
        region: ap-southeast-2  # keep me
        services: [ivs]
        tasks:
          web: {}
    YAML);

    expect(Manifest::setServiceList(['ivs', 'mediaconvert']))->toBeTrue();

    $raw = file_get_contents(BASE_PATH . '/yolo.yml');

    expect($raw)->toContain('services: [ivs, mediaconvert]')
        ->toContain('# keep me')   // comment untouched
        ->toContain('web: {}');    // unrelated keys untouched
});

it('rewrites a block-style list inline, dropping the - item children', function (): void {
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

    expect($raw)->toContain('services: [ivs, typesense, mediaconvert]')
        ->not->toContain('- ivs')                  // block children removed
        ->toContain('region: ap-southeast-2');     // sibling key preserved
});

it('inserts the services key under the env block when absent', function (): void {
    writeServiceListManifest(<<<'YAML'
    name: my-app
    environments:
      testing:
        region: ap-southeast-2
    YAML);

    expect(Manifest::setServiceList(['rekognition']))->toBeTrue();

    expect(file_get_contents(BASE_PATH . '/yolo.yml'))->toContain('services: [rekognition]');
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
    expect(file_get_contents(BASE_PATH . '/yolo.yml'))->toContain('services: [ivs]');

    expect(Manifest::setServiceList([]))->toBeTrue();
    expect(file_get_contents(BASE_PATH . '/yolo.yml'))
        ->not->toContain('services:')
        ->toContain('region: ap-southeast-2');
});
