<?php

use Codinglabs\Yolo\Helpers;

it('treats tagged releases (including pre-releases) as releases', function (string $version) {
    expect(Helpers::isReleaseVersion($version))->toBeTrue();
})->with([
    '1.0.0',
    '1.0.0-alpha.5',
    '2.3.1-beta.2',
    '0.4.0',
]);

it('treats branch and dev pins as non-releases', function (string $version) {
    expect(Helpers::isReleaseVersion($version))->toBeFalse();
})->with([
    'dev-main',
    'dev-feature-x',
    '1.0.x-dev',
    'unknown',
]);

it('reports the installed package version', function () {
    // Resolves the real installed version of the root package; we only assert it
    // is a non-empty string (the value differs between a tagged install and a
    // dev-* branch pin), since the fence's behaviour keys off isReleaseVersion().
    expect(Helpers::version())->toBeString()->not->toBeEmpty();
});
