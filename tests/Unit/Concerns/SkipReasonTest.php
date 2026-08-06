<?php

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
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

function skipReasonFor(object $step): ?string
{
    return (new SyncCommand())->skipReason($step);
}

it('skips single-scope steps in an app with tenants', function (): void {
    writeManifest(['multitenancy' => ['tenants' => ['alpha' => []]]]);

    expect(skipReasonFor(new class() implements ExecutesSoloStep
    {
        use FakeStepInvoke;
    }))
        ->toBe('single-scope step in an app with tenants');
});

it('skips per-tenant steps in a solo app', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect(skipReasonFor(new class() implements ExecutesMultitenancyStep
    {
        use FakeStepInvoke;
    }))
        ->toBe('per-tenant step in an app with no tenants');
});

// A landlord-only `multitenancy` block is multi-tenant *mode* with a single scope,
// so it takes the single-scope shape — the exact inversion that made a
// landlord-only manifest deploy as a headless worker before the split.
it('takes the single-scope shape for a landlord-only multitenancy block', function (): void {
    writeManifest(['multitenancy' => ['landlord' => ['domain' => 'app.example.com']]]);

    expect(skipReasonFor(new class() implements ExecutesSoloStep
    {
        use FakeStepInvoke;
    }))
        ->toBeNull()
        ->and(skipReasonFor(new class() implements ExecutesMultitenancyStep
        {
            use FakeStepInvoke;
        }))
        ->toBe('per-tenant step in an app with no tenants');
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
        'domain' => 'example.com',
    ]);

    expect(skipReasonFor(new class() implements ExecutesWebStep
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
