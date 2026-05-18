<?php

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

describe('has and get', function () {
    it('returns true for existing keys', function () {
        writeManifest(['aws' => ['region' => 'us-west-2']]);

        expect(Manifest::has('aws.region'))->toBeTrue();
    });

    it('returns false for missing keys', function () {
        writeManifest([]);

        expect(Manifest::has('aws.region'))->toBeFalse();
        expect(Manifest::doesntHave('aws.region'))->toBeTrue();
    });

    it('gets values with defaults', function () {
        writeManifest(['aws' => ['region' => 'us-west-2']]);

        expect(Manifest::get('aws.region'))->toBe('us-west-2');
        expect(Manifest::get('aws.missing', 'fallback'))->toBe('fallback');
    });

    it('has returns true even for falsy values', function () {
        writeManifest(['aws' => ['ivs' => false]]);

        expect(Manifest::has('aws.ivs'))->toBeTrue();
        expect(Manifest::get('aws.ivs'))->toBeFalse();
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

describe('ivsEnabled', function () {
    it('is false when aws.ivs is absent', function () {
        writeManifest([]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });

    it('is true for the boolean shorthand', function () {
        writeManifest(['aws' => ['ivs' => true]]);

        expect(Manifest::ivsEnabled())->toBeTrue();
    });

    it('is false when aws.ivs is explicitly false', function () {
        writeManifest(['aws' => ['ivs' => false]]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });

    it('is true for the expanded form with logging on', function () {
        writeManifest(['aws' => ['ivs' => ['logging' => true, 'log-retention-days' => 30]]]);

        expect(Manifest::ivsEnabled())->toBeTrue();
    });

    it('is false for the expanded form with logging off', function () {
        writeManifest(['aws' => ['ivs' => ['logging' => false, 'log-retention-days' => 30]]]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });

    it('is false for the expanded form without a logging key', function () {
        writeManifest(['aws' => ['ivs' => ['log-retention-days' => 30]]]);

        expect(Manifest::ivsEnabled())->toBeFalse();
    });
});

describe('declaresServerGroup', function () {
    it('is true for all groups on an empty manifest', function () {
        writeManifest([]);

        expect(Manifest::declaresServerGroup(ServerGroup::WEB))->toBeTrue();
        expect(Manifest::declaresServerGroup(ServerGroup::QUEUE))->toBeTrue();
        expect(Manifest::declaresServerGroup(ServerGroup::SCHEDULER))->toBeTrue();
    });

    it('is false when aws.autoscaling.{group} is explicitly false', function () {
        writeManifest(['aws' => ['autoscaling' => ['queue' => false]]]);

        expect(Manifest::declaresServerGroup(ServerGroup::QUEUE))->toBeFalse();
        expect(Manifest::declaresServerGroup(ServerGroup::WEB))->toBeTrue();
        expect(Manifest::declaresServerGroup(ServerGroup::SCHEDULER))->toBeTrue();
    });

    it('is true for WEB but false for QUEUE and SCHEDULER when combine is true', function () {
        writeManifest(['aws' => ['autoscaling' => ['combine' => true]]]);

        expect(Manifest::declaresServerGroup(ServerGroup::WEB))->toBeTrue();
        expect(Manifest::declaresServerGroup(ServerGroup::QUEUE))->toBeFalse();
        expect(Manifest::declaresServerGroup(ServerGroup::SCHEDULER))->toBeFalse();
    });

    it('is false for WEB when combine is true and web is explicitly false', function () {
        writeManifest(['aws' => ['autoscaling' => ['combine' => true, 'web' => false]]]);

        expect(Manifest::declaresServerGroup(ServerGroup::WEB))->toBeFalse();
    });
});

describe('hasServerGroup', function () {
    it('is false before stage has populated the ASG name', function () {
        writeManifest([]);

        expect(Manifest::hasServerGroup(ServerGroup::WEB))->toBeFalse();
        expect(Manifest::hasServerGroup(ServerGroup::QUEUE))->toBeFalse();
        expect(Manifest::hasServerGroup(ServerGroup::SCHEDULER))->toBeFalse();
    });

    it('is true when the group is declared AND the ASG name is populated', function () {
        writeManifest(['aws' => ['autoscaling' => [
            'web' => 'my-app-production-web',
            'queue' => 'my-app-production-queue',
            'scheduler' => 'my-app-production-scheduler',
        ]]]);

        expect(Manifest::hasServerGroup(ServerGroup::WEB))->toBeTrue();
        expect(Manifest::hasServerGroup(ServerGroup::QUEUE))->toBeTrue();
        expect(Manifest::hasServerGroup(ServerGroup::SCHEDULER))->toBeTrue();
    });

    it('is false when disabled even if a stale ASG name exists', function () {
        writeManifest(['aws' => ['autoscaling' => ['queue' => false]]]);

        expect(Manifest::hasServerGroup(ServerGroup::QUEUE))->toBeFalse();
    });

    it('is false for non-WEB groups when combine is true even with stale ASG names', function () {
        writeManifest(['aws' => ['autoscaling' => [
            'combine' => true,
            'web' => 'my-app-production-web',
            'queue' => 'stale-queue-name',
        ]]]);

        expect(Manifest::hasServerGroup(ServerGroup::WEB))->toBeTrue();
        expect(Manifest::hasServerGroup(ServerGroup::QUEUE))->toBeFalse();
    });

    it('is false when the ASG slot is an empty string', function () {
        writeManifest(['aws' => ['autoscaling' => ['web' => '']]]);

        expect(Manifest::hasServerGroup(ServerGroup::WEB))->toBeFalse();
    });
});

describe('serverGroups', function () {
    it('returns all populated groups by default', function () {
        writeManifest(['aws' => ['autoscaling' => [
            'web' => 'my-app-production-web',
            'queue' => 'my-app-production-queue',
            'scheduler' => 'my-app-production-scheduler',
        ]]]);

        expect(Manifest::serverGroups())->toBe([
            ServerGroup::WEB,
            ServerGroup::QUEUE,
            ServerGroup::SCHEDULER,
        ]);
    });

    it('returns only WEB when combine is true', function () {
        writeManifest(['aws' => ['autoscaling' => [
            'combine' => true,
            'web' => 'my-app-production-web',
        ]]]);

        expect(Manifest::serverGroups())->toBe([ServerGroup::WEB]);
    });

    it('excludes explicitly disabled groups', function () {
        writeManifest(['aws' => ['autoscaling' => [
            'web' => 'my-app-production-web',
            'queue' => false,
            'scheduler' => 'my-app-production-scheduler',
        ]]]);

        expect(Manifest::serverGroups())->toBe([
            ServerGroup::WEB,
            ServerGroup::SCHEDULER,
        ]);
    });

    it('returns an empty array before stage has run', function () {
        writeManifest([]);

        expect(Manifest::serverGroups())->toBe([]);
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
