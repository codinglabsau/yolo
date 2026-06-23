<?php

declare(strict_types=1);

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;

afterEach(function (): void {
    Manifest::flushHydration();
    writeManifest([]);
});

it('resolves reads from a hydrated manifest, shadowing yolo.yml on disk', function (): void {
    // yolo.yml on disk declares only `testing`; the hydrated manifest stands in for
    // a `typesense` env the file no longer carries (destroy:app removed the block).
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    Manifest::hydrate([
        'name' => 'reconstructed',
        'environments' => [
            'typesense' => [
                'account-id' => '222222222222',
                'region' => 'us-west-2',
                'domain' => 'search.example.com',
                'services' => ['typesense'],
            ],
        ],
    ]);
    Helpers::app()->instance('environment', 'typesense');

    expect(Manifest::environmentExists('typesense'))->toBeTrue()
        ->and(Manifest::environmentExists('testing'))->toBeFalse()
        ->and(Manifest::name())->toBe('reconstructed')
        ->and(Manifest::get('account-id'))->toBe('222222222222')
        ->and(Manifest::get('region'))->toBe('us-west-2')
        ->and(Manifest::get('domain'))->toBe('search.example.com')
        ->and(Manifest::services())->toBe(['typesense'])
        ->and(Manifest::usesService(Service::TYPESENSE))->toBeTrue();
});

it('falls back to yolo.yml once hydration is flushed', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    Manifest::hydrate(['name' => 'x', 'environments' => ['testing' => ['account-id' => '999999999999']]]);
    expect(Manifest::get('account-id'))->toBe('999999999999');

    Manifest::flushHydration();
    expect(Manifest::get('account-id'))->toBe('111111111111');
});

it('reports the manifest as existing when hydrated even with no file on disk', function (): void {
    @unlink(BASE_PATH . '/yolo.yml');
    expect(Manifest::exists())->toBeFalse();

    Manifest::hydrate(['name' => 'x', 'environments' => ['testing' => []]]);
    expect(Manifest::exists())->toBeTrue();
});
