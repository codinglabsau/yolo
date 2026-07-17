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
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails when the top-level name is missing', function (): void {
    writeRawManifest([
        'environments' => [
            'testing' => [
                'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            ],
        ],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('`name`');
});

it('bails when region is missing', function (): void {
    writeManifest([
        'account-id' => '111111111111',
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
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'flavour' => 'spicy',
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('flavour');
});

it('bails on a legacy aws.* namespaced manifest with the fully-qualified key path and a docs link', function (): void {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    $output = test()->promptOutput->fetch();
    expect($output)->toContain('environments.testing.aws.account-id');
    expect($output)->toContain('codinglabsau.github.io/yolo/reference/manifest');
});

it('bails on a key at the wrong level (cache.store under a misplaced parent)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'cache' => ['store' => 'redis', 'driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('cache.driver');
});

it('bails when a tasks block yields no runnable service', function (string $description, array $tasks): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => $tasks,
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('nothing would run');
})->with([
    // The bundled queue/scheduler have no web container to ride, and nothing is
    // extracted into its own service — nowhere to run any work.
    ['web switched off, nothing extracted', ['web' => false]],
    ['everything switched off', ['web' => false, 'queue' => false, 'scheduler' => false]],
    ['only disabled roles declared', ['queue' => false, 'scheduler' => false]],
]);

it('accepts a web-less worker app with a standalone queue or scheduler', function (array $tasks): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => $tasks,
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
})->with([
    'scheduler-only' => [['web' => false, 'queue' => false, 'scheduler' => true]],
    'queue-only worker' => [['web' => false, 'queue' => ['autoscaling' => true]]],
    'queue + scheduler worker' => [['web' => false, 'queue' => ['autoscaling' => true], 'scheduler' => true]],
]);

it('accepts a manifest with no tasks at all (a build-only app)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a supported session.driver', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'database'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails on an unknown session.driver', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'mysql'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('session.driver');
});

it('bails when session.driver is redis but cache.store is off', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('cache.store');
});

it('accepts session.driver redis when cache.store is redis', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
        'session' => ['driver' => 'redis'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a services list of known service names', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => ['ivs'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails on an unknown service name', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => ['ivs', 'memcached'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('memcached');
});

it('bails when services carries config — service shape belongs in the environment manifest', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => ['ivs' => ['log-retention-days' => 30]],
    ]);

    // A map under services flattens to unknown key paths, so the allow-list
    // catches it before the dedicated services validator even runs.
    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('services.ivs');
});

it('bails when services is not a list at all', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => 'ivs',
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('list of service names');
});

it('bails on duplicate services entries', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => ['ivs', 'ivs'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('duplicate');
});

it('bails on the removed ivs key — services: [ivs] replaced it', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'ivs' => true,
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('ivs');
});

it('bails on the removed mediaconvert key — services: [mediaconvert] replaced it', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'mediaconvert' => true,
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('mediaconvert');
});

it('accepts every known service as a consumed service', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'services' => ['ivs', 'mediaconvert', 'rekognition'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts the known shape of every task group', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => [
            'web' => [
                'cpu' => '512', 'memory' => '1024', 'platform' => 'linux/amd64',
                'enable-execute-command' => true, 'shutdown-grace-period' => 10, 'ssr' => true,
                'health-check' => ['timeout' => 8], 'autoscaling' => ['min' => 1, 'max' => 4],
            ],
            'queue' => [
                'autoscaling' => ['min' => 1, 'max' => 10, 'backlog-per-task' => 100],
                'cpu' => '256', 'memory' => '512', 'spot' => true,
                'shutdown-grace-period' => 70, 'enable-execute-command' => false,
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
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['nonsense' => true]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('tasks.web.nonsense');
});

it('bails when a web config map omits autoscaling', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['cpu' => '512']],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    $output = test()->promptOutput->fetch();
    expect($output)->toContain('tasks.web');
    expect($output)->toContain('autoscaling');
});

it('bails on the bare `tasks.web: true` shorthand — web needs an explicit autoscaling decision', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('tasks.web');
});

it('bails when a standalone queue omits autoscaling', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true], 'queue' => ['spot' => true]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    $output = test()->promptOutput->fetch();
    expect($output)->toContain('tasks.queue');
    expect($output)->toContain('autoscaling');
});

it('bails on the bare `tasks.queue: true` shorthand', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true], 'queue' => true],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('tasks.queue');
});

it('demands no autoscaling declaration of a disabled web tier', function (): void {
    // `web: false` alone would be refused (nothing runnable — see the runnable-
    // service cases above), so the disabled tier is exercised beside a scheduler.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => false, 'scheduler' => true],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('keeps the bare `tasks.scheduler: true` shorthand — the scheduler never scales', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true], 'scheduler' => true],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails when the scheduler rides a queue explicitly set to scale to zero', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true], 'queue' => ['autoscaling' => ['min' => 0]]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    $output = test()->promptOutput->fetch();
    expect($output)->toContain('tasks.queue.autoscaling.min');
    expect($output)->toContain('tasks.scheduler');
});

it('accepts a scheduler-hosting queue with a standing floor of one', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true], 'queue' => ['autoscaling' => ['min' => 1]]],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a scheduler-hosting queue with no explicit floor (defaults to one)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true], 'queue' => ['autoscaling' => true]],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a scale-to-zero queue when the scheduler is its own service', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true], 'queue' => ['autoscaling' => ['min' => 0]], 'scheduler' => true],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a task-role-policies list', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'task-role-policies' => ['arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess'],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('rejects the reserved app name `services` — it collides with the env services cluster', function (): void {
    file_put_contents(BASE_PATH . '/yolo.yml', Yaml::dump([
        'name' => 'services',
        'environments' => [
            'testing' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        ],
    ], 10, 2));
    Helpers::app()->instance('environment', 'testing');

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('reserved');
});
