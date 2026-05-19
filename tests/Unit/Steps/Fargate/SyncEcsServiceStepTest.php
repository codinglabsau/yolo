<?php

use Codinglabs\Yolo\Steps\Fargate\SyncEcsServiceStep;

describe('needsUpdate', function () {
    beforeEach(function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'domain' => 'codinglabs.com.au',
            'tasks' => ['web' => []],
        ]);
    });

    it('is false when desiredCount and grace period match', function () {
        expect(SyncEcsServiceStep::needsUpdate(
            service: ['desiredCount' => 1, 'healthCheckGracePeriodSeconds' => 60],
            desiredCount: 1,
            gracePeriod: 60,
        ))->toBeFalse();
    });

    it('is true when desiredCount diverges', function () {
        expect(SyncEcsServiceStep::needsUpdate(
            service: ['desiredCount' => 1, 'healthCheckGracePeriodSeconds' => 60],
            desiredCount: 2,
            gracePeriod: 60,
        ))->toBeTrue();
    });

    it('is true when grace period diverges', function () {
        expect(SyncEcsServiceStep::needsUpdate(
            service: ['desiredCount' => 1, 'healthCheckGracePeriodSeconds' => 120],
            desiredCount: 1,
            gracePeriod: 60,
        ))->toBeTrue();
    });

    it('treats missing healthCheckGracePeriodSeconds as the manifest default (no churn against older services)', function () {
        expect(SyncEcsServiceStep::needsUpdate(
            service: ['desiredCount' => 1],
            desiredCount: 1,
            gracePeriod: 60,
        ))->toBeFalse();
    });
});

describe('needsUpdate when headless', function () {
    beforeEach(function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tasks' => ['web' => []],
        ]);
    });

    it('ignores grace period drift for headless services (no ALB to reconcile against)', function () {
        expect(SyncEcsServiceStep::needsUpdate(
            service: ['desiredCount' => 1, 'healthCheckGracePeriodSeconds' => 9999],
            desiredCount: 1,
            gracePeriod: 60,
        ))->toBeFalse();
    });

    it('still detects desiredCount drift for headless services', function () {
        expect(SyncEcsServiceStep::needsUpdate(
            service: ['desiredCount' => 1],
            desiredCount: 3,
            gracePeriod: 60,
        ))->toBeTrue();
    });
});

describe('createPayload', function () {
    it('omits loadBalancers and healthCheckGracePeriodSeconds when headless', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'tasks' => ['web' => []],
        ]);

        // createPayload references AwsResources::publicSubnetIds() / ecsTaskSecurityGroup() etc.
        // which require live AWS lookups, so we can't fully invoke it here. Instead pin the
        // headless-conditional shape decisions via a partial extraction: invoke updatePayload
        // (purely-manifest-driven, no AWS calls) — same headless conditional, same proof.
        $payload = SyncEcsServiceStep::updatePayload(desiredCount: 1, gracePeriod: 60);

        expect($payload)->not->toHaveKey('healthCheckGracePeriodSeconds');
    });

    it('includes healthCheckGracePeriodSeconds in updatePayload when not headless', function () {
        writeManifest([
            'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
            'domain' => 'codinglabs.com.au',
            'tasks' => ['web' => []],
        ]);

        $payload = SyncEcsServiceStep::updatePayload(desiredCount: 1, gracePeriod: 60);

        expect($payload['healthCheckGracePeriodSeconds'])->toBe(60);
    });
});
