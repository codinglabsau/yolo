<?php

use Codinglabs\Yolo\Helpers;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Commands\SyncAppCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Commands\SyncSteppedCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

beforeEach(function () {
    Helpers::app()->instance('runningInAws', false);
});

/**
 * @param  array<string, array<int, class-string>>  $domains
 * @return array{0: Collection, 1: Collection} [$plan, $skipped]
 */
function collate(array $domains, ?SyncSteppedCommand $command = null, array $options = []): array
{
    $command ??= new SyncCommand();

    $input = new ArrayInput(['environment' => 'testing'] + $options, $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    return (new ReflectionMethod($command, 'collateSteps'))->invoke($command, $domains, 'testing');
}

/** A per-tenant step that needs no AWS — to exercise the fan-out / --tenant filter. */
class CollationFakeTenantStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        return StepResult::CREATED;
    }
}

it('orchestrates account → platform → app domains for a solo app without a web task', function () {
    writeManifest(['aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2']]);

    expect(array_keys((new SyncCommand())->domains()))
        ->toBe(['IAM (account)', 'Network', 'IAM (shared)', 'Load balancer', 'Storage', 'IAM (app)', 'Solo', 'Logging']);
});

it('includes the Fargate and CDN groups when the manifest declares a web task', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => ['cpu' => 512, 'memory' => 1024]],
    ]);

    expect(array_keys((new SyncCommand())->domains()))
        ->toBe(['IAM (account)', 'Network', 'IAM (shared)', 'Load balancer', 'Storage', 'IAM (app)', 'Solo', 'Fargate', 'CDN', 'Logging']);
});

it('swaps the Solo group for Landlord + Tenants on a multi-tenant app', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tenants' => ['alpha' => []],
    ]);

    expect(array_keys((new SyncCommand())->domains()))
        ->toBe(['IAM (account)', 'Network', 'IAM (shared)', 'Load balancer', 'Storage', 'IAM (app)', 'Landlord', 'Tenants', 'Logging']);
});

it('composes distinct tier labels so no group is dropped on merge', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => []],
    ]);

    $labels = array_keys((new SyncCommand())->domains());

    expect($labels)->toEqual(array_unique($labels));
});

it('groups the three skipped IVS steps under a single determination', function () {
    writeManifest(['aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2']]);

    [$plan, $skipped] = collate(['Logging' => (new SyncAppCommand())->domains()['Logging']]);

    expect($plan)->toHaveCount(0);
    expect($skipped)->toHaveCount(3);

    expect($skipped->groupBy(fn (array $entry) => $entry['domain'] . '|' . $entry['reason']))
        ->toHaveCount(1);
    expect($skipped->first()['reason'])->toBe('aws.ivs not enabled in manifest');
});

it('plans the IVS steps when aws.ivs is enabled', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'ivs' => true],
    ]);

    [$plan, $skipped] = collate(['Logging' => (new SyncAppCommand())->domains()['Logging']]);

    expect($plan)->toHaveCount(3);
    expect($skipped)->toHaveCount(0);
});

it('fans a per-tenant step out across every tenant by default', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tenants' => ['alpha' => [], 'beta' => []],
    ]);

    [$plan] = collate(['Tenants' => [CollationFakeTenantStep::class]], new SyncAppCommand());

    expect($plan->pluck('step')->map->tenantId()->all())->toBe(['alpha', 'beta']);
});

it('narrows the per-tenant fan-out to a single tenant with --tenant', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tenants' => ['alpha' => [], 'beta' => []],
    ]);

    [$plan] = collate(['Tenants' => [CollationFakeTenantStep::class]], new SyncAppCommand(), ['--tenant' => 'alpha']);

    expect($plan)->toHaveCount(1);
    expect($plan->first()['step']->tenantId())->toBe('alpha');
});

it('errors on an unknown --tenant id', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tenants' => ['alpha' => []],
    ]);

    collate(['Tenants' => [CollationFakeTenantStep::class]], new SyncAppCommand(), ['--tenant' => 'ghost']);
})->throws(IntegrityCheckException::class, 'Unknown tenant "ghost"');
