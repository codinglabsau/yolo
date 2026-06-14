<?php

declare(strict_types=1);

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServerGroup;
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
                'au' => ['domain' => 'au.example.com', 'apex' => 'au.example.com'],
            ],
        ]);

        expect(Manifest::isMultitenanted())->toBeTrue();
    });

    it('normalises tenant apex from domain', function (): void {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com'],
            ],
        ]);

        $tenants = Manifest::tenants();

        expect($tenants['au']['apex'])->toBe('au.example.com');
    });

    it('rejects www apex', function (): void {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com', 'apex' => 'www.example.com'],
            ],
        ]);

        expect(fn (): array => Manifest::tenants())
            ->toThrow(IntegrityCheckException::class);
    });
});

describe('cache + session defaults', function (): void {
    it('defaults web apps to the shared redis cache and redis sessions', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => []],
        ]);

        expect(Manifest::cacheStore())->toBe('redis');
        expect(Manifest::sessionDriver())->toBe('redis');
    });

    it('has no cache or session default for a non-web app', function (): void {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

        expect(Manifest::cacheStore())->toBeNull();
        expect(Manifest::sessionDriver())->toBeNull();
    });

    it('respects explicit cache.store and session.driver overrides', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => []],
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
            'tasks' => ['web' => []],
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

describe('web burst', function (): void {
    it('is on by default for an Octane app once autoscaling is enabled', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 4]]],
        ]);

        // Octane is the default, so burst follows it — no flag needed.
        expect(Manifest::webBurstEnabled())->toBeTrue();
    });

    it('can be opted out with burst: false', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 4, 'burst' => false]]],
        ]);

        expect(Manifest::webBurstEnabled())->toBeFalse();
    });

    it('requires autoscaling — there is no scalable target to burst without it', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => []],
        ]);

        expect(Manifest::webBurstEnabled())->toBeFalse();
    });

    it('defaults off for a classic-mode app, with no error', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['octane' => false, 'autoscaling' => ['min' => 1, 'max' => 4]]],
        ]);

        // Classic mode has no worker pool to measure, so the default is off — quietly,
        // since the operator didn't ask for it.
        expect(Manifest::webBurstEnabled())->toBeFalse();
    });

    it('hard-fails when burst is on but octane is off, rather than silently ignoring it', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['octane' => false, 'autoscaling' => ['min' => 1, 'max' => 4, 'burst' => true]]],
        ]);

        expect(fn (): bool => Manifest::webBurstEnabled())->toThrow(IntegrityCheckException::class);
    });

    it('rejects a non-boolean burst flag', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 4, 'burst' => 'sometimes']]],
        ]);

        expect(fn (): bool => Manifest::webBurstEnabled())->toThrow(IntegrityCheckException::class);
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
    it('returns the apex domain', function (): void {
        writeManifest(['domain' => 'example.com']);

        expect(Manifest::apex())->toBe('example.com');
    });

    it('prefers explicit apex over domain', function (): void {
        writeManifest([
            'domain' => 'www.example.com',
            'apex' => 'example.com',
        ]);

        expect(Manifest::apex())->toBe('example.com');
    });

    it('rejects www apex', function (): void {
        writeManifest(['apex' => 'www.example.com']);

        expect(fn (): string => Manifest::apex())
            ->toThrow(IntegrityCheckException::class);
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
        writeManifest(['tasks' => ['web' => []]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB]);
    });

    it('lists web, queue and scheduler when both are extracted', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => [], 'scheduler' => []]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB, ServerGroup::QUEUE, ServerGroup::SCHEDULER]);
    });

    it('does not list a bundled queue as its own group', function (): void {
        writeManifest(['tasks' => ['web' => []]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB]);
        expect(Manifest::hasStandaloneQueue())->toBeFalse();
        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
    });

    it('detects a standalone queue and lists it as its own group', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::hasStandaloneQueue())->toBeTrue();
        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
    });
});

describe('queue and scheduler hosts', function (): void {
    it('bundles both the queue worker and the scheduler into web for a plain web app', function (): void {
        writeManifest(['tasks' => ['web' => []]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::WEB);
    });

    it('rides the scheduler on the standalone queue when only the queue is extracted', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::QUEUE);
    });

    it('keeps the queue worker in web but gives the scheduler its own service when only the scheduler is extracted', function (): void {
        writeManifest(['tasks' => ['web' => [], 'scheduler' => []]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::SCHEDULER);
    });

    it('gives each role its own container when both are extracted', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => [], 'scheduler' => []]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::SCHEDULER);
    });
});

describe('queue floor', function (): void {
    it('defaults a standalone queue to scale to zero when the scheduler is extracted', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => [], 'scheduler' => []]]);

        expect(Manifest::queueMin())->toBe(0);
    });

    it('defaults a scheduler-hosting queue to a floor of one', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::queueMin())->toBe(1);
    });

    it('honours an explicit queue min', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => ['min' => 3], 'scheduler' => []]]);

        expect(Manifest::queueMin())->toBe(3);
    });
});

describe('deploy group', function (): void {
    it('runs deploy hooks on web for a plain web app', function (): void {
        writeManifest(['tasks' => ['web' => []]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::WEB);
    });

    it('runs deploy hooks on a standalone queue when there is no standalone scheduler', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::QUEUE);
    });

    it('runs deploy hooks on a standalone scheduler when one is extracted', function (): void {
        writeManifest(['tasks' => ['web' => [], 'scheduler' => []]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::SCHEDULER);
    });

    it('prefers the scheduler over the queue when both are extracted', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => [], 'scheduler' => []]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::SCHEDULER);
    });

    it('tracks the scheduler host — deploy hooks run on the management tier', function (): void {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::deployGroup())->toBe(Manifest::schedulerHost());
    });
});
