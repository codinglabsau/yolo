<?php

use Codinglabs\Yolo\Helpers;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Commands\SyncLoggingCommand;

beforeEach(function () {
    Helpers::app()->instance('runningInAws', false);
});

/**
 * @return array<string, array<int, class-string>>
 */
function umbrellaDomains(): array
{
    $command = new SyncCommand();
    $method = new ReflectionMethod($command, 'domains');

    return $method->invoke($command);
}

/**
 * @param  array<string, array<int, class-string>>  $domains
 * @return array{0: Collection, 1: Collection} [$plan, $skipped]
 */
function collate(array $domains): array
{
    $command = new SyncCommand();
    $method = new ReflectionMethod($command, 'collateSteps');

    return $method->invoke($command, $domains, 'testing');
}

it('collates solo domains without compute when there is no web task', function () {
    writeManifest(['aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2']]);

    expect(array_keys(umbrellaDomains()))
        ->toBe(['Network', 'Storage', 'IAM', 'Solo', 'Logging']);
});

it('includes compute when the manifest declares a web task', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => ['cpu' => 512, 'memory' => 1024]],
    ]);

    expect(array_keys(umbrellaDomains()))
        ->toBe(['Network', 'Storage', 'IAM', 'Solo', 'Compute', 'Logging']);
});

it('collates landlord and tenant domains for a multi-tenant app', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tenants' => ['alpha' => []],
    ]);

    expect(array_keys(umbrellaDomains()))
        ->toBe(['Network', 'Storage', 'IAM', 'Landlord', 'Tenants', 'Logging']);
});

it('groups the three skipped IVS steps under a single determination', function () {
    writeManifest(['aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2']]);

    [$plan, $skipped] = collate(['Logging' => (new SyncLoggingCommand())->steps()]);

    expect($plan)->toHaveCount(0);
    expect($skipped)->toHaveCount(3);

    // All three collapse to one (domain, reason) determination line.
    expect($skipped->groupBy(fn (array $entry) => $entry['domain'] . '|' . $entry['reason']))
        ->toHaveCount(1);
    expect($skipped->first()['reason'])->toBe('aws.ivs not enabled in manifest');
});

it('plans the IVS steps when aws.ivs is enabled', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'ivs' => true],
    ]);

    [$plan, $skipped] = collate(['Logging' => (new SyncLoggingCommand())->steps()]);

    expect($plan)->toHaveCount(3);
    expect($skipped)->toHaveCount(0);
});
