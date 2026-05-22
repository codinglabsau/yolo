<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\Iam\IamClient;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
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

$tempDir = sys_get_temp_dir() . '/yolo-test-' . (getenv('TEST_TOKEN') ?: getmypid());

@mkdir($tempDir, 0755, true);

if (! defined('BASE_PATH')) {
    define('BASE_PATH', $tempDir);
}

file_put_contents($tempDir . '/yolo.yml', Yaml::dump([
    'name' => 'my-app',
    'environments' => [
        'testing' => [],
    ],
], 10, 2));

Helpers::app()->instance('environment', 'testing');

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

function writeManifest(array $config, string $environment = 'testing'): void
{
    file_put_contents(BASE_PATH . '/yolo.yml', Yaml::dump([
        'name' => 'my-app',
        'environments' => [
            $environment => $config,
        ],
    ], 10, 2));

    Helpers::app()->instance('environment', $environment);
}

/**
 * Bind a mock IAM client whose ListRoles returns the supplied roles. The handler
 * repeats (no MockHandler queue), so any number of lookups — task role, execution
 * role, etc. — resolve without exhausting a queue.
 *
 * @param  array<string, string>  $roles  roleName => roleArn
 */
function bindMockIamClient(array $roles): void
{
    $result = new Result([
        'Roles' => collect($roles)
            ->map(fn (string $arn, string $name) => ['RoleName' => $name, 'Arn' => $arn])
            ->values()
            ->all(),
        'IsTruncated' => false,
    ]);

    $mock = new class($result) extends MockHandler
    {
        public function __construct(protected Result $result) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor($this->result);
        }
    };

    Helpers::app()->instance('iam', new IamClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}
