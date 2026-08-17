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

it('refuses a web task with no public host — web requires a domain', function (array $manifest): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        ...$manifest,
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('no `domain`');
})->with([
    // No listener rule ever routes to a domain-less web task — it's a web
    // server nobody can reach. Workers (no web task) are the headless shape.
    'solo, no domain' => [['tasks' => ['web' => ['autoscaling' => true]]]],
    'multi-tenant, no tenant domains' => [[
        'multitenancy' => ['tenants' => ['alpha' => [], 'beta' => []]],
        'tasks' => ['web' => ['autoscaling' => true]],
    ]],
]);

it('accepts a web task when a public host exists', function (array $manifest): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        ...$manifest,
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
})->with([
    'solo with a domain' => [['domain' => 'example.com', 'tasks' => ['web' => ['autoscaling' => true]]]],
    // One routed tenant is enough — a domain-less sibling may be mid-onboarding.
    'multi-tenant with one tenant domain' => [[
        'multitenancy' => ['tenants' => ['alpha' => ['domain' => 'alpha.example.com'], 'beta' => []]],
        'tasks' => ['web' => ['autoscaling' => true]],
    ]],
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
        'domain' => 'example.com',
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
        'domain' => 'example.com',
        'tasks' => ['web' => ['nonsense' => true]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('tasks.web.nonsense');
});

it('bails when a web config map omits autoscaling', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'example.com',
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
        'domain' => 'example.com',
        'tasks' => ['web' => true],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();
    expect(test()->promptOutput->fetch())->toContain('tasks.web');
});

it('bails when a standalone queue omits autoscaling', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'example.com',
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
        'domain' => 'example.com',
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
        'domain' => 'example.com',
        'tasks' => ['web' => ['autoscaling' => true], 'scheduler' => true],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails when the scheduler rides a queue explicitly set to scale to zero', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'example.com',
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
        'domain' => 'example.com',
        'tasks' => ['web' => ['autoscaling' => true], 'queue' => ['autoscaling' => ['min' => 1]]],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a scheduler-hosting queue with no explicit floor (defaults to one)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'example.com',
        'tasks' => ['web' => ['autoscaling' => true], 'queue' => ['autoscaling' => true]],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a scale-to-zero queue when the scheduler is its own service', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'example.com',
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

it('bails when queue-isolation is set on a solo app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['queue-isolation' => 'dedicated'],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('queue-isolation');
});

describe('the multitenancy block', function (): void {
    // Every key that moved is refused where it used to live, naming the exact path
    // it moved to — an "unknown key" error would be correct and useless.
    it('names the new path for a relocated root key', function (string $manifest, string $expected): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            ...json_decode($manifest, true),
        ]);

        expect(invokeManifestIntegrity())->toBeFalse();
        expect(test()->promptOutput->fetch())->toContain($expected);
    })->with([
        'tenants' => ['{"tenants":{"acme":[]}}', 'multitenancy.tenants'],
        'queue-isolation' => ['{"queue-isolation":"dedicated"}', 'multitenancy.queue-isolation'],
        'domain' => [
            '{"domain":"example.com","multitenancy":{"tenants":{"acme":null}}}',
            'multitenancy.landlord.domain',
        ],
        'wildcard-subdomains' => [
            '{"wildcard-subdomains":true,"multitenancy":{"tenants":{"acme":null}}}',
            'multitenancy.landlord.wildcard-subdomains',
        ],
    ]);

    it('refuses a key the multitenancy block does not name', function (): void {
        // `apex` is always derived from `domain`. Accepting it silently — which the
        // old free-form `tenants.*` subtree did — meant a hand-written value was
        // taken and then overwritten.
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'multitenancy' => ['tenants' => ['acme' => ['domain' => 'acme.test', 'apex' => 'acme.test']]],
        ]);

        expect(invokeManifestIntegrity())->toBeFalse();
        expect(test()->promptOutput->fetch())->toContain('multitenancy.tenants.acme.apex');
    });

    it('accepts a tenant declared bare, with no config of its own', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'multitenancy' => [
                'landlord' => ['domain' => 'app.example.com', 'wildcard-subdomains' => true],
                'tenants' => ['acme' => null, 'globex' => null],
            ],
        ]);

        expect(invokeManifestIntegrity())->toBeTrue();
    });
});

it('bails on an unknown queue-isolation value', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['queue-isolation' => 'sometimes', 'tenants' => ['acme' => []]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('queue-isolation');
});

it('passes for a shared multi-tenant app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['queue-isolation' => 'shared', 'tenants' => ['acme' => []]],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('bails when wildcard-subdomains is set with no domain to be a wildcard of', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'wildcard-subdomains' => true,
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('wildcard-subdomains');
});

it('bails when wildcard-subdomains and tenants are both declared', function (): void {
    // Two different tenancy models — one host with a wildcard, versus a zone and
    // certificate per tenant. Declaring both says nothing coherent.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['tenants' => ['acme' => ['domain' => 'acme.example.com']]],
        'wildcard-subdomains' => true,
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('wildcard-subdomains');
});

it('bails on wildcard-subdomains with a www-canonical domain', function (): void {
    // The wildcard would land at *.www.{apex}, and moving the certificate onto the
    // www host leaves the apex it redirects from with no certificate covering it —
    // a TLS failure before the 301 could fire.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'www.example.com',
        'wildcard-subdomains' => true,
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('www-canonical');
});

it('passes for a wildcard-subdomain app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'app.example.com',
        'wildcard-subdomains' => true,
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts `bucket: true` — the YOLO-owned app data bucket', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => true,
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('accepts a bucket name to adopt', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-bucket',
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('refuses `bucket: false` rather than reading it as no bucket', function (): void {
    // Omitting the key already says "no bucket". Reading `false` as that too would
    // silently ship an app with no AWS_BUCKET when the intent was clearly to have one.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => false,
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('bucket');
});

it('refuses a bucket name S3 would reject, rather than failing mid-apply', function (): void {
    foreach (['My-App-Bucket', 'ab', 'bucket..name', '10.0.0.1', 'trailing-'] as $invalid) {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => $invalid,
        ]);

        expect(invokeManifestIntegrity())->toBeFalse();
    }
});

it('accepts a landlord domain declared as a list of hosts', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['app.example.com', 'app.example.io']]],
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});

it('refuses an empty landlord domain list', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => []]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('empty list');
});

it('refuses a blank host in a landlord domain list', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['app.example.com', '']]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('blank or non-string');
});

it('refuses a duplicate host in a landlord domain list', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => ['app.example.com', 'app.example.com']]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('duplicate host');
});

it('refuses more landlord domains than an ALB host-header rule can hold', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => [
            'a.example.com', 'b.example.com', 'c.example.com', 'd.example.com', 'e.example.com', 'f.example.com',
        ]]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('ALB allows at most 5');
});

it('counts the wildcard against the ALB host-header limit when wildcard-subdomains is on', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'multitenancy' => ['landlord' => ['domain' => [
            'a.example.com', 'b.example.com', 'c.example.com', 'd.example.com', 'e.example.com',
        ], 'wildcard-subdomains' => true]],
    ]);

    expect(invokeManifestIntegrity())->toBeFalse();

    expect(test()->promptOutput->fetch())->toContain('ALB allows at most 5');
});

it('leaves a solo domain untouched by the landlord domain-list validation', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'example.com',
    ]);

    expect(invokeManifestIntegrity())->toBeTrue();
});
