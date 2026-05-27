<?php

use Codinglabs\Yolo\Resources\Ecs\EcsService;

describe('serviceNeedsUpdate', function () {
    beforeEach(function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'domain' => 'codinglabs.com.au',
            'tasks' => ['web' => []],
        ]);
    });

    it('is false when grace period and exec flag match', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => true, 'healthCheckGracePeriodSeconds' => 60],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeFalse();
    });

    it('is true when the grace period diverges', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => true, 'healthCheckGracePeriodSeconds' => 120],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeTrue();
    });

    it('is true when the exec-command flag diverges (so manifest toggles take effect on sync)', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => false, 'healthCheckGracePeriodSeconds' => 60],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeTrue();
    });

    it('does NOT reconcile desiredCount — capacity is owned by ops, not the manifest', function () {
        // A manual scale (or autoscaling) to 5 must not trip an update.
        expect(EcsService::serviceNeedsUpdate(
            service: ['desiredCount' => 5, 'enableExecuteCommand' => true, 'healthCheckGracePeriodSeconds' => 60],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeFalse();
    });

    it('treats missing healthCheckGracePeriodSeconds as the manifest default (no churn against older services)', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => true],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeFalse();
    });
});

describe('serviceNeedsUpdate when headless', function () {
    beforeEach(function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tasks' => ['web' => []],
        ]);
    });

    it('ignores grace period drift for headless services (no ALB to reconcile against)', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => true, 'healthCheckGracePeriodSeconds' => 9999],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeFalse();
    });

    it('still reconciles the exec flag for headless services', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => false],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeTrue();
    });
});

describe('updatePayload', function () {
    it('omits healthCheckGracePeriodSeconds when headless', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tasks' => ['web' => []],
        ]);

        // which require live AWS lookups, so we can't fully invoke it here. updatePayload is
        // purely manifest-driven (no AWS lookups), so it pins the headless conditional shape.
        $payload = (new EcsService())->updatePayload();

        expect($payload)->not->toHaveKey('healthCheckGracePeriodSeconds');
    });

    it('includes healthCheckGracePeriodSeconds when not headless', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'domain' => 'codinglabs.com.au',
            'tasks' => ['web' => ['health-check' => ['grace-period' => 60]]],
        ]);

        expect((new EcsService())->updatePayload()['healthCheckGracePeriodSeconds'])->toBe(60);
    });

    it('reconciles the exec-command flag', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tasks' => ['web' => ['enable-execute-command' => true]],
        ]);

        expect((new EcsService())->updatePayload()['enableExecuteCommand'])->toBeTrue();
    });

    it('never includes desiredCount — capacity is set once at create, never reset on update', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tasks' => ['web' => []],
        ]);

        expect((new EcsService())->updatePayload())->not->toHaveKey('desiredCount');
    });
});

describe('deploymentConfiguration', function () {
    it('enables the circuit breaker with rollback so a failed rollout self-reverts', function () {
        expect(EcsService::deploymentConfiguration()['deploymentCircuitBreaker'])
            ->toBe(['enable' => true, 'rollback' => true]);
    });

    it('keeps one-at-a-time rolling capacity (100% min healthy, 200% max)', function () {
        $config = EcsService::deploymentConfiguration();

        expect($config['minimumHealthyPercent'])->toBe(100);
        expect($config['maximumPercent'])->toBe(200);
    });
});
