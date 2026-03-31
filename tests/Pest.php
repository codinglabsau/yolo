<?php

use Codinglabs\Yolo\Helpers;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Bootstrap
|--------------------------------------------------------------------------
|
| Set up a temporary manifest and environment so tests can use Manifest,
| Helpers::keyedResourceName(), and other static helpers without touching
| a real yolo.yml or AWS.
|
*/

$tempDir = sys_get_temp_dir() . '/yolo-test';

@mkdir($tempDir, 0755, true);

if (! defined('BASE_PATH')) {
    define('BASE_PATH', $tempDir);
}

file_put_contents($tempDir . '/yolo.yml', Yaml::dump([
    'name' => 'my-app',
    'environments' => [
        'production' => [],
        'testing' => [],
    ],
], 10, 2));

Helpers::app()->instance('environment', 'production');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function writeManifest(array $config, string $environment = 'production'): void
{
    file_put_contents(BASE_PATH . '/yolo.yml', Yaml::dump([
        'name' => 'my-app',
        'environments' => [
            $environment => $config,
        ],
    ], 10, 2));

    Helpers::app()->instance('environment', $environment);
}
