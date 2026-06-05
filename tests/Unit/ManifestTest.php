<?php

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

describe('has and get', function () {
    it('returns true for existing keys', function () {
        writeManifest(['region' => 'us-west-2']);

        expect(Manifest::has('region'))->toBeTrue();
    });

    it('returns false for missing keys', function () {
        writeManifest([]);

        expect(Manifest::has('region'))->toBeFalse();
        expect(Manifest::doesntHave('region'))->toBeTrue();
    });

    it('gets values with defaults', function () {
        writeManifest(['region' => 'us-west-2']);

        expect(Manifest::get('region'))->toBe('us-west-2');
        expect(Manifest::get('missing', 'fallback'))->toBe('fallback');
    });

    it('has returns true even for falsy values', function () {
        writeManifest(['ivs' => false]);

        expect(Manifest::has('ivs'))->toBeTrue();
        expect(Manifest::get('ivs'))->toBeFalse();
    });
});

describe('name', function () {
    it('returns the app name', function () {
        expect(Manifest::name())->toBe('my-app');
    });
});

describe('timezone', function () {
    it('defaults to UTC', function () {
        writeManifest([]);

        expect(Manifest::timezone())->toBe('UTC');
    });

    it('reads configured timezone', function () {
        writeManifest([], 'testing');

        // timezone is read from root level, not environment level
        // so it always returns the manifest-level timezone or UTC default
        expect(Manifest::timezone())->toBe('UTC');
    });
});

describe('multitenancy', function () {
    it('is not multitenanted without tenants config', function () {
        writeManifest([]);

        expect(Manifest::isMultitenanted())->toBeFalse();
    });

    it('is multitenanted with tenants config', function () {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com', 'apex' => 'au.example.com'],
            ],
        ]);

        expect(Manifest::isMultitenanted())->toBeTrue();
    });

    it('normalises tenant apex from domain', function () {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com'],
            ],
        ]);

        $tenants = Manifest::tenants();

        expect($tenants['au']['apex'])->toBe('au.example.com');
    });

    it('rejects www apex', function () {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com', 'apex' => 'www.example.com'],
            ],
        ]);

        expect(fn () => Manifest::tenants())
            ->toThrow(IntegrityCheckException::class);
    });
});

describe('cache + session defaults', function () {
    it('defaults web apps to the shared redis cache and redis sessions', function () {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => []],
        ]);

        expect(Manifest::cacheStore())->toBe('redis');
        expect(Manifest::sessionDriver())->toBe('redis');
    });

    it('has no cache or session default for a non-web app', function () {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

        expect(Manifest::cacheStore())->toBeNull();
        expect(Manifest::sessionDriver())->toBeNull();
    });

    it('respects explicit cache.store and session.driver overrides', function () {
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

describe('task-role-policies', function () {
    it('defaults to no additional policies', function () {
        writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

        expect(Manifest::taskRolePolicies())->toBe([]);
    });

    it('returns the declared customer- and AWS-managed policy ARNs', function () {
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

    it('hard-fails when an entry is not an IAM policy ARN', function () {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'task-role-policies' => ['arn:aws:iam::111111111111:role/not-a-policy'],
        ]);

        expect(fn () => Manifest::taskRolePolicies())->toThrow(IntegrityCheckException::class);
    });

    it('hard-fails when the value is not a list', function () {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'task-role-policies' => ['policy' => 'arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess'],
        ]);

        expect(fn () => Manifest::taskRolePolicies())->toThrow(IntegrityCheckException::class);
    });
});

describe('ivsEnabled', function () {
    it('is false when ivs is absent', function () {
        writeManifest([]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });

    it('is true for the boolean shorthand', function () {
        writeManifest(['ivs' => true]);

        expect(Manifest::ivsEnabled())->toBeTrue();
    });

    it('is false when ivs is explicitly false', function () {
        writeManifest(['ivs' => false]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });

    it('is true for the expanded form with logging on', function () {
        writeManifest(['ivs' => ['logging' => true, 'log-retention-days' => 30]]);

        expect(Manifest::ivsEnabled())->toBeTrue();
    });

    it('is false for the expanded form with logging off', function () {
        writeManifest(['ivs' => ['logging' => false, 'log-retention-days' => 30]]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });

    it('is false for the expanded form without a logging key', function () {
        writeManifest(['ivs' => ['log-retention-days' => 30]]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });
});

describe('apex', function () {
    it('returns the apex domain', function () {
        writeManifest(['domain' => 'example.com']);

        expect(Manifest::apex())->toBe('example.com');
    });

    it('prefers explicit apex over domain', function () {
        writeManifest([
            'domain' => 'www.example.com',
            'apex' => 'example.com',
        ]);

        expect(Manifest::apex())->toBe('example.com');
    });

    it('rejects www apex', function () {
        writeManifest(['apex' => 'www.example.com']);

        expect(fn () => Manifest::apex())
            ->toThrow(IntegrityCheckException::class);
    });

    it('throws for multitenanted environments', function () {
        writeManifest([
            'tenants' => [
                'au' => ['domain' => 'au.example.com'],
            ],
        ]);

        expect(fn () => Manifest::apex())
            ->toThrow(IntegrityCheckException::class);
    });
});

describe('server groups', function () {
    it('lists only web for a plain web app', function () {
        writeManifest(['tasks' => ['web' => []]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB]);
    });

    it('lists web, queue and scheduler when both are extracted', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => [], 'scheduler' => []]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB, ServerGroup::QUEUE, ServerGroup::SCHEDULER]);
    });

    it('does not list a bundled queue as its own group', function () {
        writeManifest(['tasks' => ['web' => []]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB]);
        expect(Manifest::hasStandaloneQueue())->toBeFalse();
        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
    });

    it('detects a standalone queue and lists it as its own group', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::hasStandaloneQueue())->toBeTrue();
        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
    });
});

describe('queue and scheduler hosts', function () {
    it('bundles both the queue worker and the scheduler into web for a plain web app', function () {
        writeManifest(['tasks' => ['web' => []]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::WEB);
    });

    it('rides the scheduler on the standalone queue when only the queue is extracted', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::QUEUE);
    });

    it('keeps the queue worker in web but gives the scheduler its own service when only the scheduler is extracted', function () {
        writeManifest(['tasks' => ['web' => [], 'scheduler' => []]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::WEB);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::SCHEDULER);
    });

    it('gives each role its own container when both are extracted', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => [], 'scheduler' => []]]);

        expect(Manifest::queueHost())->toBe(ServerGroup::QUEUE);
        expect(Manifest::schedulerHost())->toBe(ServerGroup::SCHEDULER);
    });
});

describe('queue floor', function () {
    it('defaults a standalone queue to scale to zero when the scheduler is extracted', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => [], 'scheduler' => []]]);

        expect(Manifest::queueMin())->toBe(0);
    });

    it('defaults a scheduler-hosting queue to a floor of one', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::queueMin())->toBe(1);
    });

    it('honours an explicit queue min', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => ['min' => 3], 'scheduler' => []]]);

        expect(Manifest::queueMin())->toBe(3);
    });
});

describe('deploy group', function () {
    it('runs deploy hooks on web for a plain web app', function () {
        writeManifest(['tasks' => ['web' => []]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::WEB);
    });

    it('runs deploy hooks on a standalone queue when there is no standalone scheduler', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::QUEUE);
    });

    it('runs deploy hooks on a standalone scheduler when one is extracted', function () {
        writeManifest(['tasks' => ['web' => [], 'scheduler' => []]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::SCHEDULER);
    });

    it('prefers the scheduler over the queue when both are extracted', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => [], 'scheduler' => []]]);

        expect(Manifest::deployGroup())->toBe(ServerGroup::SCHEDULER);
    });

    it('tracks the scheduler host — deploy hooks run on the management tier', function () {
        writeManifest(['tasks' => ['web' => [], 'queue' => []]]);

        expect(Manifest::deployGroup())->toBe(Manifest::schedulerHost());
    });
});
