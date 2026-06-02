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

it('bails on a legacy aws.* namespaced manifest', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('aws.region');
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
        'session' => ['driver' => 'dynamodb'],
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
