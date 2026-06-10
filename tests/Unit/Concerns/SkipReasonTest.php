<?php

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Contracts\ExecutesIvsStep;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Contracts\ExecutesSoloStep;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

/**
 * skipReason() only reads a step's marker interfaces, never invokes it — so
 * these doubles just need a no-op __invoke to satisfy the Step contract.
 */
trait FakeStepInvoke
{
    public function __invoke(array $options): StepResult
    {
        return StepResult::SUCCESS;
    }
}

beforeEach(function (): void {
    // skipReason() consults Aws::runningInAws() for steps that pass every
    // structural gate; bind it so the "should run" path resolves locally.
    Helpers::app()->instance('runningInAws', false);
});

function skipReasonFor(object $step): ?string
{
    return (new SyncCommand())->skipReason($step);
}

it('skips solo-only steps in a multi-tenant app', function (): void {
    writeManifest(['tenants' => ['alpha' => []]]);

    expect(skipReasonFor(new class() implements ExecutesSoloStep
    {
        use FakeStepInvoke;
    }))
        ->toBe('solo-only step in a multi-tenant app');
});

it('skips multi-tenancy steps in a solo app', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect(skipReasonFor(new class() implements ExecutesMultitenancyStep
    {
        use FakeStepInvoke;
    }))
        ->toBe('multi-tenancy step in a solo app');
});

it('skips web steps for a headless app', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect(skipReasonFor(new class() implements ExecutesWebStep
    {
        use FakeStepInvoke;
    }))
        ->toBe('headless app (no ALB / Route 53 / domain)');
});

it('runs web steps when the app has a domain', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
    ]);

    expect(skipReasonFor(new class() implements ExecutesWebStep
    {
        use FakeStepInvoke;
    }))->toBeNull();
});

it('skips IVS steps when ivs is not enabled', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect(skipReasonFor(new class() implements ExecutesIvsStep
    {
        use FakeStepInvoke;
    }))
        ->toBe('ivs not declared in manifest services');
});

it('runs IVS steps when ivs is enabled', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['ivs'],
    ]);

    expect(skipReasonFor(new class() implements ExecutesIvsStep
    {
        use FakeStepInvoke;
    }))->toBeNull();
});

it('runs a plain step with no structural gate', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect(skipReasonFor(new class() implements Step
    {
        use FakeStepInvoke;
    }))->toBeNull();
});
