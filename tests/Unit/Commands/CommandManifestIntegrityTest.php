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

it('returns true for a manifest declaring name, aws.region, and aws.account-id', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails when the top-level name is missing', function () {
    writeRawManifest([
        'environments' => [
            'testing' => [
                'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
            ],
        ],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('`name`');
});

it('bails when aws.region is missing', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('aws.region');
});

it('bails when aws.account-id is missing', function () {
    writeManifest([
        'aws' => ['region' => 'ap-southeast-2'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('aws.account-id');
});

it('accepts a supported session.driver', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
        'session' => ['driver' => 'dynamodb'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails on an unknown session.driver', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
        'session' => ['driver' => 'mysql'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('session.driver');
});

it('bails when session.driver is redis but aws.cache is off', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
        'session' => ['driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('aws.cache');
});

it('accepts session.driver redis when aws.cache is enabled', function () {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2', 'cache' => true],
        'session' => ['driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});
