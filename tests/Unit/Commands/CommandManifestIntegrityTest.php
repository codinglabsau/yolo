<?php

use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Symfony\Component\Yaml\Yaml;
use Codinglabs\Yolo\Commands\SyncCommand;
use Symfony\Component\Console\Output\BufferedOutput;

function invokeManifestIntegrity(): bool
{
    $command = new SyncCommand();
    $method = new ReflectionMethod($command, 'ensureManifestIntegrity');

    return $method->invoke($command);
}

function writeRawManifest(array $manifest): void
{
    file_put_contents(BASE_PATH . '/yolo.yml', Yaml::dump($manifest, 10, 2));
    Helpers::app()->instance('environment', 'testing');
}

beforeEach(function () {
    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

it('returns true for a manifest declaring name, region, and account-id', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails when the top-level name is missing', function () {
    writeRawManifest([
        'environments' => [
            'testing' => [
                'account-id' => '848509375702', 'region' => 'ap-southeast-2',
            ],
        ],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('`name`');
});

it('bails when region is missing', function () {
    writeManifest([
        'account-id' => '848509375702',
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('region');
});

it('bails when account-id is missing', function () {
    writeManifest([
        'region' => 'ap-southeast-2',
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('account-id');
});

it('bails on an unknown environment key', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'flavour' => 'spicy',
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('flavour');
});

it('bails on a legacy aws.* namespaced manifest with the fully-qualified key path and a docs link', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    $output = test()->promptOutput->fetch();
    expect($output)->toContain('environments.testing.aws.account-id');
    expect($output)->toContain('codinglabsau.github.io/yolo/reference/manifest');
});

it('bails on a key at the wrong level (cache.store under a misplaced parent)', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'cache' => ['store' => 'redis', 'driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('cache.driver');
});

it('accepts a supported session.driver', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'database'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails on an unknown session.driver', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'mysql'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('session.driver');
});

it('bails when session.driver is redis but cache.store is off', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('cache.store');
});

it('accepts session.driver redis when cache.store is redis', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
        'session' => ['driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails when the queue is both bundled and a standalone service', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => true], 'queue' => ['min' => 0]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    $output = test()->promptOutput->fetch();
    expect($output)->toContain('tasks.web.queue');
    expect($output)->toContain('tasks.queue');
});

it('bails when the scheduler is both bundled and a standalone service', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['scheduler' => true], 'scheduler' => []],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('tasks.scheduler');
});

it('accepts a bundled queue with a standalone scheduler (mix and match per workload)', function () {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['queue' => true], 'scheduler' => []],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});
