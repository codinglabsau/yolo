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

beforeEach(function (): void {
    $buffer = new BufferedOutput();
    Prompt::setOutput($buffer);
    test()->promptOutput = $buffer;
});

it('returns true for a manifest declaring name, region, and account-id', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails when the top-level name is missing', function (): void {
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

it('bails when region is missing', function (): void {
    writeManifest([
        'account-id' => '848509375702',
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('region');
});

it('bails when account-id is missing', function (): void {
    writeManifest([
        'region' => 'ap-southeast-2',
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('account-id');
});

it('bails on an unknown environment key', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'flavour' => 'spicy',
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('flavour');
});

it('bails on a legacy aws.* namespaced manifest with the fully-qualified key path and a docs link', function (): void {
    writeManifest([
        'aws' => ['account-id' => '848509375702', 'region' => 'ap-southeast-2'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    $output = test()->promptOutput->fetch();
    expect($output)->toContain('environments.testing.aws.account-id');
    expect($output)->toContain('codinglabsau.github.io/yolo/reference/manifest');
});

it('bails on a key at the wrong level (cache.store under a misplaced parent)', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'cache' => ['store' => 'redis', 'driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('cache.driver');
});

it('accepts a supported session.driver', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'database'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails on an unknown session.driver', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'mysql'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('session.driver');
});

it('bails when session.driver is redis but cache.store is off', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('cache.store');
});

it('accepts session.driver redis when cache.store is redis', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
        'session' => ['driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts the known shape of every task group', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'tasks' => [
            'web' => [
                'port' => 8000, 'cpu' => '512', 'memory' => '1024', 'platform' => 'linux/amd64',
                'enable-execute-command' => true, 'shutdown-grace-period' => 10, 'ssr' => true,
                'health-check' => ['timeout' => 8], 'autoscaling' => ['min' => 1, 'max' => 4],
            ],
            'queue' => [
                'min' => 1, 'max' => 10, 'backlog-per-task' => 100, 'cpu' => '256',
                'memory' => '512', 'spot' => true, 'shutdown-grace-period' => 70,
                'enable-execute-command' => false,
            ],
            'scheduler' => [
                'cpu' => '256', 'memory' => '512', 'shutdown-grace-period' => 10,
                'enable-execute-command' => false,
            ],
        ],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails on an unrecognised key inside a task group', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['nonsense' => true]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('tasks.web.nonsense');
});

it('bails when the scheduler rides a queue explicitly set to scale to zero', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => ['min' => 0]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    $output = test()->promptOutput->fetch();
    expect($output)->toContain('tasks.queue.min');
    expect($output)->toContain('tasks.scheduler');
});

it('accepts a scheduler-hosting queue with a standing floor of one', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => ['min' => 1]],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a scheduler-hosting queue with no explicit floor (defaults to one)', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a scale-to-zero queue when the scheduler is its own service', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => ['min' => 0], 'scheduler' => []],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a task-role-policies list', function (): void {
    writeManifest([
        'account-id' => '848509375702', 'region' => 'ap-southeast-2',
        'task-role-policies' => ['arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});
