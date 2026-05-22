<?php

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Contracts\ExecutesIvsStep;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Contracts\ExecutesSoloStep;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

beforeEach(function () {
    // skipReason() consults Aws::runningInAws() for steps that pass every
    // structural gate; bind it so the "should run" path resolves locally.
    Helpers::app()->instance('runningInAws', false);
});

function skipReasonFor(object $step): ?string
{
    return (new SyncCommand())->skipReason($step);
}

it('skips solo-only steps in a multi-tenant app', function () {
    writeManifest(['tenants' => ['alpha' => []]]);

    expect(skipReasonFor(new class() implements ExecutesSoloStep {}))
        ->toBe('solo-only step in a multi-tenant app');
});

it('skips multi-tenancy steps in a solo app', function () {
    writeManifest(['aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2']]);

    expect(skipReasonFor(new class() implements ExecutesMultitenancyStep {}))
        ->toBe('multi-tenancy step in a solo app');
});

it('skips web steps for a headless app', function () {
    writeManifest(['aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2']]);

    expect(skipReasonFor(new class() implements ExecutesWebStep {}))
        ->toBe('headless app (no ALB / Route 53 / domain)');
});

it('runs web steps when the app has a domain', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'domain' => 'codinglabs.com.au',
    ]);

    expect(skipReasonFor(new class() implements ExecutesWebStep {}))->toBeNull();
});

it('skips IVS steps when aws.ivs is not enabled', function () {
    writeManifest(['aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2']]);

    expect(skipReasonFor(new class() implements ExecutesIvsStep {}))
        ->toBe('aws.ivs not enabled in manifest');
});

it('runs IVS steps when aws.ivs is enabled', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'ivs' => true],
    ]);

    expect(skipReasonFor(new class() implements ExecutesIvsStep {}))->toBeNull();
});

it('runs a plain step with no structural gate', function () {
    writeManifest(['aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2']]);

    expect(skipReasonFor(new class() implements Step {}))->toBeNull();
});
