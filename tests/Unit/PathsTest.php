<?php

declare(strict_types=1);

use Codinglabs\Yolo\Paths;

describe('path building', function (): void {
    it('builds base path', function (): void {
        expect(Paths::base())->toBe(BASE_PATH);
        expect(Paths::base('src'))->toBe(BASE_PATH . '/src');
    });

    it('builds yolo path', function (): void {
        expect(Paths::yolo())->toBe(BASE_PATH . '/.yolo');
        expect(Paths::yolo('build'))->toBe(BASE_PATH . '/.yolo/build');
    });

    it('builds build path', function (): void {
        expect(Paths::build())->toBe(BASE_PATH . '/.yolo/build');
        expect(Paths::build('public'))->toBe(BASE_PATH . '/.yolo/build/public');
    });

    it('resolves manifest path', function (): void {
        expect(Paths::manifest())->toBe(BASE_PATH . '/yolo.yml');
    });
});

describe('s3 bucket names', function (): void {
    beforeEach(function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
    });

    it('names the artefacts bucket per account + environment + app', function (): void {
        expect(Paths::s3ArtefactsBucket())->toBe('yolo-111111111111-testing-my-app-artefacts');
    });

    it('names the environment bucket per account + environment', function (): void {
        expect(Paths::s3EnvironmentBucket())->toBe('yolo-111111111111-testing');
    });
});
