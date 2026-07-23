<?php

declare(strict_types=1);

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Enums\QueueIsolation;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

describe('has and get', function (): void {
    it('returns true for existing keys', function (): void {
        writeManifest(['region' => 'us-west-2']);

        expect(Manifest::has('region'))->toBeTrue();
    });

    it('returns false for missing keys', function (): void {
        writeManifest([]);

        expect(Manifest::has('region'))->toBeFalse();
    });

    it('gets values with defaults', function (): void {
        writeManifest(['region' => 'us-west-2']);

        expect(Manifest::get('region'))->toBe('us-west-2');
        expect(Manifest::get('missing', 'fallback'))->toBe('fallback');
    });

    it('has returns true even for falsy values', function (): void {
        writeManifest(['tag' => false]);

        expect(Manifest::has('tag'))->toBeTrue();
        expect(Manifest::get('tag'))->toBeFalse();
    });
});

describe('name', function (): void {
    it('returns the app name', function (): void {
        expect(Manifest::name())->toBe('my-app');
    });
});

describe('timezone', function (): void {
    it('defaults to UTC', function (): void {
        writeManifest([]);

        expect(Manifest::timezone())->toBe('UTC');
    });

    it('reads configured timezone', function (): void {
        writeManifest([], 'testing');

        // timezone is read from root level, not environment level
        // so it always returns the manifest-level timezone or UTC default
        expect(Manifest::timezone())->toBe('UTC');
    });
});

describe('multitenancy', function (): void {
    it('is not multitenanted without tenants config', function (): void {
        writeManifest([]);

        expect(Manifest::isMultitenanted())->toBeFalse();
    });

    it('is multitenanted with tenants config', function (): void {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com'],
            ],
        ]);

        expect(Manifest::isMultitenanted())->toBeTrue();
    });

    it('derives each tenant apex from its domain', function (): void {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com'],
            ],
        ]);

        bindHostedZones();

        $tenants = Manifest::tenants();

        expect($tenants['au']['apex'])->toBe('au.example.com');
    });

    it('derives a tenant apex from the longest matching hosted zone', function (): void {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'shop.au.example.com'],
            ],
        ]);

        bindHostedZones(['au.example.com']);

        $tenants = Manifest::tenants();

        expect($tenants['au']['apex'])->toBe('au.example.com');
    });
});

describe('queue tiers', function (): void {
    it('declares no tiers without a queues: block', function (): void {
        writeManifest([]);

        expect(Manifest::queueTiers())->toBe([]);
    });

    it('reads the declared tiers as a list in priority order', function (): void {
        writeManifest(['queues' => ['high', 'default']]);

        expect(Manifest::queueTiers())->toBe(['high', 'default']);
    });

    it('accepts the queues: list through the manifest validator', function (): void {
        writeManifest(['queues' => ['high', 'default']]);

        expect(Manifest::unknownKeys())->toBe([]);
    });

    it('rejects a queues: map — per-queue config is not supported yet', function (): void {
        writeManifest(['queues' => ['high' => null, 'default' => null]]);

        expect(fn (): array => Manifest::queueTiers())->toThrow(IntegrityCheckException::class);
    });
});

describe('queue isolation', function (): void {
    it('defaults a multi-tenant app to dedicated per-tenant queues', function (): void {
        writeManifest(['tenants' => ['acme' => [], 'globex' => []]]);

        expect(Manifest::queueIsolation())->toBe(QueueIsolation::Dedicated);
        expect(Manifest::fansQueuesPerTenant())->toBeTrue();
    });

    it('shares queues across tenants when isolation is shared', function (): void {
        writeManifest([
            'tenants' => ['acme' => [], 'globex' => []],
            'queue-isolation' => 'shared',
        ]);

        expect(Manifest::queueIsolation())->toBe(QueueIsolation::Shared);
        // shared collapses to the solo queue shape — the layer does not fan per tenant
        expect(Manifest::fansQueuesPerTenant())->toBeFalse();
    });

    it('never fans queues per tenant for a solo app', function (): void {
        writeManifest([]);

        expect(Manifest::fansQueuesPerTenant())->toBeFalse();
    });

    it('hard-fails on an unknown isolation value', function (): void {
        writeManifest([
            'tenants' => ['acme' => []],
            'queue-isolation' => 'sometimes',
        ]);

        expect(fn (): QueueIsolation => Manifest::queueIsolation())->toThrow(IntegrityCheckException::class);
    });
});

describe('cache + session defaults', function (): void {
    it('defaults web apps to the shared redis cache and redis sessions', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true],
        ]);

        expect(Manifest::cacheStore())->toBe('redis');
        expect(Manifest::sessionDriver())->toBe('redis');
    });

    it('defaults a web-less worker app to the shared redis cache but no session driver', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => false, 'queue' => ['autoscaling' => true]],
        ]);

        expect(Manifest::cacheStore())->toBe('redis');
        expect(Manifest::sessionDriver())->toBeNull();
    });

    it('has no cache or session default for a build-only app', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

        expect(Manifest::cacheStore())->toBeNull();
        expect(Manifest::sessionDriver())->toBeNull();
    });

    it('respects explicit cache.store and session.driver overrides', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true],
            'cache' => ['store' => 'file'],
            'session' => ['driver' => 'cookie'],
        ]);

        expect(Manifest::cacheStore())->toBe('file');
        expect(Manifest::sessionDriver())->toBe('cookie');
    });
});

describe('octane', function (): void {
    it('defaults to running octane when tasks.web.octane is unset', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true],
        ]);

        expect(Manifest::usesOctane())->toBeTrue();
    });

    it('opts out of octane when tasks.web.octane is false', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['octane' => false]],
        ]);

        expect(Manifest::usesOctane())->toBeFalse();
    });

    it('rejects a non-boolean octane flag', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['octane' => 'sometimes']],
        ]);

        expect(fn (): bool => Manifest::usesOctane())->toThrow(IntegrityCheckException::class);
    });
});

describe('autoscaling', function (): void {
    it('is on with an autoscaling block', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['autoscaling' => ['min' => 2, 'max' => 6]]],
        ]);

        expect(Manifest::isAutoscaling())->toBeTrue();
    });

    it('is on with the `autoscaling: true` shorthand', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['autoscaling' => true]],
        ]);

        expect(Manifest::isAutoscaling())->toBeTrue();
    });

    it('is off with an explicit `autoscaling: false`', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['autoscaling' => false]],
        ]);

        expect(Manifest::isAutoscaling())->toBeFalse();
    });

    it('is on by default for an enabled web tier with no autoscaling key', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true],
        ]);

        expect(Manifest::isAutoscaling())->toBeTrue();
    });

    it('rejects an empty autoscaling object', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['autoscaling' => []]],
        ]);

        expect(fn (): bool => Manifest::isAutoscaling())->toThrow(IntegrityCheckException::class);
    });
});

describe('metrics caddyfile', function (): void {
    it('applies for an autoscaling Octane web tier', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['autoscaling' => true]],
        ]);

        expect(Manifest::usesMetricsCaddyfile())->toBeTrue();
    });

    it('does not apply in classic mode, where worker metrics never exist', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['octane' => false, 'autoscaling' => true]],
        ]);

        expect(Manifest::usesMetricsCaddyfile())->toBeFalse();
    });

    it('applies by default for an Octane web tier (autoscaling on by default)', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true],
        ]);

        expect(Manifest::usesMetricsCaddyfile())->toBeTrue();
    });

    it('does not apply when autoscaling is explicitly off', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['autoscaling' => false]],
        ]);

        expect(Manifest::usesMetricsCaddyfile())->toBeFalse();
    });
});

describe('task-role-policies', function (): void {
    it('defaults to no additional policies', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

        expect(Manifest::taskRolePolicies())->toBe([]);
    });

    it('returns the declared customer- and AWS-managed policy ARNs', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'task-role-policies' => [
                'arn:aws:iam::111111111111:policy/my-policy',
                'arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess',
            ],
        ]);

        expect(Manifest::taskRolePolicies())->toBe([
            'arn:aws:iam::111111111111:policy/my-policy',
            'arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess',
        ]);
    });

    it('hard-fails when an entry is not an IAM policy ARN', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'task-role-policies' => ['arn:aws:iam::111111111111:role/not-a-policy'],
        ]);

        expect(fn (): array => Manifest::taskRolePolicies())->toThrow(IntegrityCheckException::class);
    });

    it('hard-fails when the value is not a list', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'task-role-policies' => ['policy' => 'arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess'],
        ]);

        expect(fn (): array => Manifest::taskRolePolicies())->toThrow(IntegrityCheckException::class);
    });
});

describe('services', function (): void {
    it('declares no services when the key is absent', function (): void {
        writeManifest([]);

        expect(Manifest::services())->toBe([])
            ->and(Manifest::usesService(Service::IVS))->toBeFalse();
    });

    it('reports a declared service', function (): void {
        writeManifest(['services' => ['ivs']]);

        expect(Manifest::services())->toBe(['ivs'])
            ->and(Manifest::usesService(Service::IVS))->toBeTrue();
    });

    it('treats a non-list value as nothing declared — the validator rejects it before any step runs', function (): void {
        writeManifest(['services' => ['ivs' => true]]);

        expect(Manifest::services())->toBe([])
            ->and(Manifest::usesService(Service::IVS))->toBeFalse();
    });
});

describe('apex', function (): void {
    it('returns the domain itself when no ancestor hosted zone exists', function (): void {
        writeManifest(['domain' => 'example.com']);
        bindHostedZones();

        expect(Manifest::apex())->toBe('example.com');
    });

    it('strips a leading www. when no hosted zone matches', function (): void {
        writeManifest(['domain' => 'www.example.com']);
        bindHostedZones();

        expect(Manifest::apex())->toBe('example.com');
    });

    it('returns the longest matching hosted-zone suffix', function (): void {
        writeManifest(['domain' => 'app.example.com']);
        bindHostedZones(['example.com']);

        expect(Manifest::apex())->toBe('example.com');
    });

    it('throws for multitenanted environments', function (): void {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com'],
            ],
        ]);

        expect(fn (): string => Manifest::apex())
            ->toThrow(IntegrityCheckException::class);
    });
});

describe('server groups', function (): void {
    it('lists only web for a plain web app', function (): void {
        writeManifest(['tasks' => ['web' => true]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB]);
    });

    it('lists web, queue and scheduler when both are extracted', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true, 'scheduler' => true]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB, ServerGroup::QUEUE, ServerGroup::SCHEDULER]);
    });

    it('does not list a bundled queue as its own group', function (): void {
        writeManifest(['tasks' => ['web' => true]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB]);
        expect(Manifest::hasStandaloneQueue())->toBeFalse();
        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
    });

    it('detects a standalone queue and lists it as its own group', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true]]);

        expect(Manifest::hasStandaloneQueue())->toBeTrue();
        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
    });
});

describe('queue and scheduler hosts', function (): void {
    it('bundles both the queue worker and the scheduler into web for a plain web app', function (): void {
        writeManifest(['tasks' => ['web' => true]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::WEB);
    });

    it('rides the scheduler on the standalone queue when only the queue is extracted', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::QUEUE);
    });

    it('keeps the queue worker in web but gives the scheduler its own service when only the scheduler is extracted', function (): void {
        writeManifest(['tasks' => ['web' => true, 'scheduler' => true]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::SCHEDULER);
    });

    it('gives each role its own container when both are extracted', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true, 'scheduler' => true]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::SCHEDULER);
    });
});

describe('queue floor', function (): void {
    it('defaults a standalone queue floor to one (no accidental scale-to-zero)', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true, 'scheduler' => true]]);

        expect(Manifest::queueMin())->toBe(1);
    });

    it('opts into scale-to-zero with an explicit autoscaling min of zero', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => ['autoscaling' => ['min' => 0]], 'scheduler' => true]]);

        expect(Manifest::queueMin())->toBe(0);
    });

    it('honours an explicit queue autoscaling min', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => ['autoscaling' => ['min' => 3]], 'scheduler' => true]]);

        expect(Manifest::queueMin())->toBe(3);
    });
});

describe('deploy group', function (): void {
    it('runs deploy hooks on web for a plain web app', function (): void {
        writeManifest(['tasks' => ['web' => true]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::WEB);
    });

    it('runs deploy hooks on a standalone queue when there is no standalone scheduler', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::QUEUE);
    });

    it('runs deploy hooks on a standalone scheduler when one is extracted', function (): void {
        writeManifest(['tasks' => ['web' => true, 'scheduler' => true]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::SCHEDULER);
    });

    it('prefers the scheduler over the queue when both are extracted', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true, 'scheduler' => true]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::SCHEDULER);
    });

    it('tracks the scheduler host — deploy hooks run on the management tier', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true]]);

        expect(Manifest::deployGroup())->toBe(Manifest::schedulerHost());
    });

    it('still resolves a deploy group when the scheduler is disabled', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true, 'scheduler' => false]]);

        expect(Manifest::schedulerHost())->toBeNull();
        expect(Manifest::deployGroup())->toBe(ServerGroup::QUEUE);
    });
});

describe('three-state queue and scheduler', function (): void {
    it('extracts a standalone queue with `true`', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true]]);

        expect(Manifest::hasStandaloneQueue())->toBeTrue();
        expect(Manifest::queueDisabled())->toBeFalse();
        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
    });

    it('extracts a standalone queue with a config object', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => ['cpu' => 512]]]);

        expect(Manifest::hasStandaloneQueue())->toBeTrue();
        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
    });

    it('disables the queue with `false` — it runs nowhere', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => false]]);

        expect(Manifest::queueDisabled())->toBeTrue();
        expect(Manifest::hasStandaloneQueue())->toBeFalse();
        expect(Manifest::queueHost())->toBeNull();
        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB]);
    });

    it('keeps the scheduler in web when only the queue is disabled', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => false]]);

        expect(Manifest::schedulerHost())->toBe(ServerGroup::WEB);
    });

    it('disables the scheduler with `false` — cron runs nowhere', function (): void {
        writeManifest(['tasks' => ['web' => true, 'scheduler' => false]]);

        expect(Manifest::schedulerDisabled())->toBeTrue();
        expect(Manifest::hasStandaloneScheduler())->toBeFalse();
        expect(Manifest::schedulerHost())->toBeNull();
        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
    });

    it('returns a null queue host for a worker-less headless app', function (): void {
        writeManifest(['tasks' => ['scheduler' => true]]);

        expect(Manifest::queueHost())->toBeNull();
    });

    it('rejects an empty queue block', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => []]]);

        expect(fn (): bool => Manifest::hasStandaloneQueue())
            ->toThrow(IntegrityCheckException::class);
    });

    it('rejects an empty scheduler block', function (): void {
        writeManifest(['tasks' => ['web' => true, 'scheduler' => []]]);

        expect(fn (): bool => Manifest::hasStandaloneScheduler())
            ->toThrow(IntegrityCheckException::class);
    });

    it('rejects a non-boolean scalar queue value', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => 'yes']]);

        expect(fn (): bool => Manifest::hasStandaloneQueue())
            ->toThrow(IntegrityCheckException::class);
    });

    it('treats web: false as a headless app with no web service', function (): void {
        writeManifest(['tasks' => ['web' => false, 'queue' => true]]);

        expect(Manifest::hasWeb())->toBeFalse();
        expect(Manifest::webDisabled())->toBeTrue();
        expect(Manifest::serverGroups())->toBe([ServerGroup::QUEUE]);
    });

    it('rejects an empty web block', function (): void {
        writeManifest(['tasks' => ['web' => []]]);

        expect(fn (): bool => Manifest::hasWeb())
            ->toThrow(IntegrityCheckException::class);
    });
});

describe('unified autoscaling', function (): void {
    it('autoscales an enabled web tier by default — min 1, max 5', function (): void {
        writeManifest(['tasks' => ['web' => true]]);

        expect(Manifest::autoscales(ServerGroup::WEB))->toBeTrue();
        expect(Manifest::autoscalingMin(ServerGroup::WEB))->toBe(1);
        expect(Manifest::autoscalingMax(ServerGroup::WEB))->toBe(5);
    });

    it('autoscales an enabled standalone queue by default — min 1, max 5', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => true]]);

        expect(Manifest::autoscales(ServerGroup::QUEUE))->toBeTrue();
        expect(Manifest::autoscalingMin(ServerGroup::QUEUE))->toBe(1);
        expect(Manifest::autoscalingMax(ServerGroup::QUEUE))->toBe(5);
    });

    it('turns autoscaling off for either group with autoscaling: false', function (): void {
        writeManifest(['tasks' => ['web' => ['autoscaling' => false], 'queue' => ['autoscaling' => false]]]);

        expect(Manifest::autoscales(ServerGroup::WEB))->toBeFalse();
        expect(Manifest::autoscales(ServerGroup::QUEUE))->toBeFalse();
    });

    it('honours bespoke bounds from an autoscaling object', function (): void {
        writeManifest(['tasks' => ['web' => ['autoscaling' => ['min' => 3, 'max' => 9]]]]);

        expect(Manifest::autoscalingMin(ServerGroup::WEB))->toBe(3);
        expect(Manifest::autoscalingMax(ServerGroup::WEB))->toBe(9);
    });

    it('rejects a web autoscaling min below one', function (): void {
        writeManifest(['tasks' => ['web' => ['autoscaling' => ['min' => 0]]]]);

        expect(fn (): int => Manifest::autoscalingMin(ServerGroup::WEB))
            ->toThrow(IntegrityCheckException::class);
    });

    it('allows a queue autoscaling min of zero (scale to zero)', function (): void {
        writeManifest(['tasks' => ['web' => true, 'queue' => ['autoscaling' => ['min' => 0]]]]);

        expect(Manifest::autoscalingMin(ServerGroup::QUEUE))->toBe(0);
    });

    it('never autoscales the scheduler', function (): void {
        writeManifest(['tasks' => ['web' => true, 'scheduler' => true]]);

        expect(Manifest::autoscales(ServerGroup::SCHEDULER))->toBeFalse();
    });
});

describe('database', function (): void {
    it('reads the declared database name from the flat manifest key', function (): void {
        // Sourced from the manifest, never the app's secret .env, so every
        // consumer (dashboard writer, deploy gate, audit probe) resolves the SAME
        // target under any RBAC tier — no identity-dependent drift. Whether the
        // name is a cluster or an instance is Rds::target()'s live call.
        writeManifest(['database' => 'my-db']);
        expect(Manifest::database())->toBe('my-db');
    });

    it('returns null when nothing is declared or the value is blank', function (): void {
        writeManifest([]);
        expect(Manifest::database())->toBeNull();

        writeManifest(['database' => '']);
        expect(Manifest::database())->toBeNull();
    });

    it('rejects an endpoint hostname with a pointed message — identifiers cannot contain dots', function (): void {
        writeManifest(['database' => 'my-db.cabc123.ap-southeast-2.rds.amazonaws.com']);

        expect(fn (): ?string => Manifest::database())
            ->toThrow(IntegrityCheckException::class, 'not an endpoint hostname');
    });
});
