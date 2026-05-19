<?php

use Codinglabs\Yolo\Paths;

describe('path building', function () {
    it('builds base path', function () {
        expect(Paths::base())->toBe(BASE_PATH);
        expect(Paths::base('src'))->toBe(BASE_PATH . '/src');
    });

    it('builds yolo path', function () {
        expect(Paths::yolo())->toBe(BASE_PATH . '/.yolo');
        expect(Paths::yolo('build'))->toBe(BASE_PATH . '/.yolo/build');
    });

    it('builds build path', function () {
        expect(Paths::build())->toBe(BASE_PATH . '/.yolo/build');
        expect(Paths::build('public'))->toBe(BASE_PATH . '/.yolo/build/public');
    });

    it('resolves manifest path', function () {
        expect(Paths::manifest())->toBe(BASE_PATH . '/yolo.yml');
    });

    it('resolves artefact path', function () {
        expect(Paths::artefact())->toBe(BASE_PATH . '/.yolo/artefact.tar.gz');
    });

    it('builds s3 artefact paths', function () {
        expect(Paths::s3Artefacts('v1.0'))
            ->toBe('artefacts/v1.0');

        expect(Paths::s3Artefacts('v1.0', 'app.tar.gz'))
            ->toBe('artefacts/v1.0/app.tar.gz');
    });

    it('builds yolo dir for aws instances', function () {
        expect(Paths::yoloDir())
            ->toBe('/home/ubuntu/yolo/yolo-testing-my-app');
    });

    it('builds log dir for aws instances', function () {
        expect(Paths::logDir())
            ->toBe('/var/log/yolo/yolo-testing-my-app');
    });
});
