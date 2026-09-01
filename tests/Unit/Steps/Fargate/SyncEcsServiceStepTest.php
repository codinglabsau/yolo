<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsService;

describe('serviceNeedsUpdate', function (): void {
    beforeEach(function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'example.com',
            'tasks' => ['web' => true],
        ]);
    });

    it('is false when grace period and exec flag match', function (): void {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => true, 'healthCheckGracePeriodSeconds' => 60],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeFalse();
    });

    it('is true when the grace period diverges', function (): void {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => true, 'healthCheckGracePeriodSeconds' => 120],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeTrue();
    });

    it('is true when the exec-command flag diverges (so manifest toggles take effect on sync)', function (): void {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => false, 'healthCheckGracePeriodSeconds' => 60],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeTrue();
    });

    it('does NOT reconcile desiredCount — capacity is owned by ops, not the manifest', function (): void {
        // A manual scale (or autoscaling) to 5 must not trip an update.
        expect(EcsService::serviceNeedsUpdate(
            service: ['desiredCount' => 5, 'enableExecuteCommand' => true, 'healthCheckGracePeriodSeconds' => 60],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeFalse();
    });

    it('treats missing healthCheckGracePeriodSeconds as the manifest default (no churn against older services)', function (): void {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => true],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeFalse();
    });
});

describe('serviceNeedsUpdate when headless', function (): void {
    beforeEach(function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true],
        ]);
    });

    it('ignores grace period drift for headless services (no ALB to reconcile against)', function (): void {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => true, 'healthCheckGracePeriodSeconds' => 9999],
            gracePeriod: 60,
            enableExecuteCommand: true,
            reconcilesGracePeriod: false,
        ))->toBeFalse();
    });

    it('still reconciles the exec flag for headless services', function (): void {
        expect(EcsService::serviceNeedsUpdate(
            service: ['enableExecuteCommand' => false],
            gracePeriod: 60,
            enableExecuteCommand: true,
        ))->toBeTrue();
    });
});

describe('updatePayload', function (): void {
    it('includes healthCheckGracePeriodSeconds from the manifest', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'example.com',
            'tasks' => ['web' => ['health-check' => ['grace-period' => 60]]],
        ]);

        expect((new EcsService())->updatePayload()['healthCheckGracePeriodSeconds'])->toBe(60);
    });

    it('reconciles the exec-command flag', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => ['enable-execute-command' => true]],
        ]);

        expect((new EcsService())->updatePayload()['enableExecuteCommand'])->toBeTrue();
    });

    it('never includes desiredCount — capacity is set once at create, never reset on update', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true],
        ]);

        expect((new EcsService())->updatePayload())->not->toHaveKey('desiredCount');
    });
});

describe('deploymentConfiguration', function (): void {
    beforeEach(function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
        ]);
    });

    it('enables the circuit breaker with rollback so a failed rollout self-reverts', function (): void {
        expect((new EcsService())->deploymentConfiguration()['deploymentCircuitBreaker'])
            ->toBe(['enable' => true, 'rollback' => true]);
    });

    it('keeps one-at-a-time rolling capacity for web (100% min healthy, 200% max)', function (): void {
        $config = (new EcsService(ServerGroup::WEB))->deploymentConfiguration();

        expect($config['minimumHealthyPercent'])->toBe(100);
        expect($config['maximumPercent'])->toBe(200);
    });

    it('deploys the scheduler stop-then-start (0% min healthy, 100% max) so a rollout never runs two crons', function (): void {
        $config = (new EcsService(ServerGroup::SCHEDULER))->deploymentConfiguration();

        expect($config['minimumHealthyPercent'])->toBe(0);
        expect($config['maximumPercent'])->toBe(100);
    });
});
