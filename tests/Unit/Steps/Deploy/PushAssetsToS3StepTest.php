<?php

use Aws\Command;
use Symfony\Component\Filesystem\Filesystem;
use Codinglabs\Yolo\Steps\Deploy\PushAssetsToS3Step;

afterEach(function () {
    if (isset($this->root) && is_dir($this->root)) {
        (new Filesystem())->remove($this->root);
    }
});

function seedPublic(array $files): string
{
    $root = sys_get_temp_dir() . '/yolo-public-' . uniqid();

    foreach ($files as $relative) {
        $path = "$root/$relative";
        is_dir($directory = dirname($path)) || mkdir($directory, 0777, true);
        file_put_contents($path, 'x');
    }

    return $root;
}

function uploadKeys(string $root): array
{
    $keys = array_map(
        fn (string $path) => substr($path, strlen($root) + 1),
        iterator_to_array(PushAssetsToS3Step::uploadableFiles($root), false),
    );

    sort($keys);

    return $keys;
}

it('uploads ordinary assets across the whole public/ tree', function () {
    $this->root = seedPublic([
        'build/assets/app-abc123.js',
        'build/assets/app-abc123.css',
        'favicon.ico',
        'svg/logo.svg',
        'pwa/icon-512.png',
    ]);

    expect(uploadKeys($this->root))->toBe([
        'build/assets/app-abc123.css',
        'build/assets/app-abc123.js',
        'favicon.ico',
        'pwa/icon-512.png',
        'svg/logo.svg',
    ]);
});

it('never ships dotfiles, dot-directories or source maps to the public CDN', function () {
    $this->root = seedPublic([
        '.env',
        '.htaccess',
        '.git/config',
        'build/assets/app-abc123.js',
        'build/assets/app-abc123.js.map',
        'uploads/.DS_Store',
        'uploads/photo.jpg',
    ]);

    expect(uploadKeys($this->root))->toBe([
        'build/assets/app-abc123.js',
        'uploads/photo.jpg',
    ]);
});

it('stamps the immutable cache-control onto uploaded objects', function () {
    $put = new Command('PutObject');
    PushAssetsToS3Step::applyCacheControl($put);

    expect($put['CacheControl'])->toBe('public, max-age=31536000, immutable');

    $multipart = new Command('CreateMultipartUpload');
    PushAssetsToS3Step::applyCacheControl($multipart);

    expect($multipart['CacheControl'])->toBe('public, max-age=31536000, immutable');
});

it('leaves non-upload commands untouched', function () {
    $get = new Command('GetObject');
    PushAssetsToS3Step::applyCacheControl($get);

    expect(isset($get['CacheControl']))->toBeFalse();
});
