<?php

use Codinglabs\Yolo\Resources\Fargate\EcsService;

describe('serviceNeedsUpdate', function () {
    beforeEach(function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'domain' => 'codinglabs.com.au',
            'tasks' => ['web' => []],
        ]);
    });

    it('is false when desiredCount and grace period match', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['desiredCount' => 1, 'healthCheckGracePeriodSeconds' => 60],
            desiredCount: 1,
            gracePeriod: 60,
        ))->toBeFalse();
    });

    it('is true when desiredCount diverges', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['desiredCount' => 1, 'healthCheckGracePeriodSeconds' => 60],
            desiredCount: 2,
            gracePeriod: 60,
        ))->toBeTrue();
    });

    it('is true when grace period diverges', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['desiredCount' => 1, 'healthCheckGracePeriodSeconds' => 120],
            desiredCount: 1,
            gracePeriod: 60,
        ))->toBeTrue();
    });

    it('treats missing healthCheckGracePeriodSeconds as the manifest default (no churn against older services)', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['desiredCount' => 1],
            desiredCount: 1,
            gracePeriod: 60,
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
            service: ['desiredCount' => 1, 'healthCheckGracePeriodSeconds' => 9999],
            desiredCount: 1,
            gracePeriod: 60,
        ))->toBeFalse();
    });

    it('still detects desiredCount drift for headless services', function () {
        expect(EcsService::serviceNeedsUpdate(
            service: ['desiredCount' => 1],
            desiredCount: 3,
            gracePeriod: 60,
        ))->toBeTrue();
    });
});

describe('updatePayload', function () {
    it('omits healthCheckGracePeriodSeconds when headless', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tasks' => ['web' => []],
        ]);

        // createPayload references AwsLookups::publicSubnetIds() / ecsTaskSecurityGroup() etc.
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

    it('reads desiredCount from the manifest', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tasks' => ['web' => ['desired-count' => 3]],
        ]);

        expect((new EcsService())->updatePayload()['desiredCount'])->toBe(3);
    });
});
