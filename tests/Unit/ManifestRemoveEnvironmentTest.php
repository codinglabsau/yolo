<?php

declare(strict_types=1);

use Codinglabs\Yolo\Manifest;
use Symfony\Component\Yaml\Yaml;

function writeRemoveEnvManifest(string $yaml): void
{
    file_put_contents(BASE_PATH . '/yolo.yml', $yaml);
}

it('removes a middle environment block, preserving siblings, comments and blank lines', function (): void {
    writeRemoveEnvManifest(<<<'YAML'
    name: my-app

    environments:
      production:
        domain: example.com  # the real one
        services:
          - typesense

      typesense:
        domain: typesense.example.com
        tasks:
          web: {}

      staging:
        domain: staging.example.com
    YAML);

    expect(Manifest::removeEnvironment('typesense'))->toBeTrue();

    expect(file_get_contents(BASE_PATH . '/yolo.yml'))->toBe(<<<'YAML'
    name: my-app

    environments:
      production:
        domain: example.com  # the real one
        services:
          - typesense

      staging:
        domain: staging.example.com
    YAML);
});

it('removes the last environment block', function (): void {
    writeRemoveEnvManifest(<<<'YAML'
    name: my-app

    environments:
      production:
        domain: example.com

      typesense:
        domain: typesense.example.com
        tasks:
          web: {}
    YAML);

    expect(Manifest::removeEnvironment('typesense'))->toBeTrue();

    $raw = file_get_contents(BASE_PATH . '/yolo.yml');

    expect($raw)->toContain('production:')->not->toContain('typesense');
    expect(array_keys(Yaml::parse($raw)['environments']))->toBe(['production']);
});

it('refuses (false) and leaves the file untouched when the layout is too ambiguous to edit safely', function (): void {
    // An inline (flow) environments map can't be surgically block-edited — bail
    // rather than risk corrupting it.
    $yaml = <<<'YAML'
    name: my-app
    environments: { production: { domain: example.com }, typesense: { domain: ts.example.com } }
    YAML;
    writeRemoveEnvManifest($yaml);

    expect(Manifest::removeEnvironment('typesense'))->toBeFalse();
    expect(file_get_contents(BASE_PATH . '/yolo.yml'))->toBe($yaml);
});

it('is a safe no-op when the environment is already absent', function (): void {
    writeRemoveEnvManifest(<<<'YAML'
    name: my-app
    environments:
      production:
        domain: example.com
    YAML);

    expect(Manifest::removeEnvironment('typesense'))->toBeTrue();
    expect(array_keys(Yaml::parse(file_get_contents(BASE_PATH . '/yolo.yml'))['environments']))->toBe(['production']);
});
