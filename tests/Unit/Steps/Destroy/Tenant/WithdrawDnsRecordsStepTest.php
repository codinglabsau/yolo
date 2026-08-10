<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Aws\Route53\Route53Client;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Destroy\App\Tenant\WithdrawDnsRecordsStep;

// A tenant on its own custom domain (not served under the landlord's wildcard)
// is the only shape that reaches Route 53 here — the wildcard/no-domain skips
// are covered in MultitenancyTest.php's "per-tenant DNS/TLS steps" group.
beforeEach(function (): void {
    writeManifest(['multitenancy' => [
        'landlord' => ['domain' => 'app.example.com', 'wildcard-subdomains' => true],
        'tenants' => ['acme' => ['domain' => 'acme.com.au']],
    ]]);
});

function tenantStep(): WithdrawDnsRecordsStep
{
    return (new WithdrawDnsRecordsStep())->setTenantId('acme')->setConfig(Manifest::tenants()['acme']);
}

it('withdraws the tenant\'s own records and never deletes its hosted zone', function (): void {
    $captured = [];
    bindTenantDnsRoutedClient([
        'ListHostedZones' => new Result(['HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'acme.com.au.']]]),
        'ListResourceRecordSets' => new Result(['ResourceRecordSets' => [
            ['Name' => 'acme.com.au.', 'Type' => 'A', 'ResourceRecords' => [['Value' => '1.1.1.1']]],
            ['Name' => 'acme.com.au.', 'Type' => 'AAAA', 'ResourceRecords' => [['Value' => '::1']]],
            ['Name' => 'www.acme.com.au.', 'Type' => 'A', 'ResourceRecords' => [['Value' => '1.1.1.1']]],
            ['Name' => 'acme.com.au.', 'Type' => 'MX', 'ResourceRecords' => [['Value' => '10 mail']]],
        ]]),
    ], $captured);

    $step = tenantStep();

    expect($step(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))
        ->toContain('ChangeResourceRecordSets')
        ->not->toContain('DeleteHostedZone');

    // Named per record (type + host) — the MX (and the zone) are never touched,
    // and the app's own wildcard host never leaks into a tenant's withdrawal.
    expect(collect($step->changes())->map(fn ($change): string => $change->attribute)->all())
        ->toBe(['A acme.com.au', 'AAAA acme.com.au', 'A www.acme.com.au']);
});

it('reports WOULD_DELETE on the plan pass without writing', function (): void {
    $captured = [];
    bindTenantDnsRoutedClient([
        'ListHostedZones' => new Result(['HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'acme.com.au.']]]),
        'ListResourceRecordSets' => new Result(['ResourceRecordSets' => [
            ['Name' => 'acme.com.au.', 'Type' => 'A', 'ResourceRecords' => [['Value' => '1.1.1.1']]],
        ]]),
    ], $captured);

    expect(tenantStep()(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(array_column($captured, 'name'))->not->toContain('ChangeResourceRecordSets');
});

it('skips when the tenant\'s hosted zone does not exist', function (): void {
    $captured = [];
    bindTenantDnsRoutedClient([
        'ListHostedZones' => new Result(['HostedZones' => []]),
    ], $captured);

    expect(tenantStep()(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($captured, 'name'))->not->toContain('ChangeResourceRecordSets');
});

it('skips when the tenant\'s zone holds none of its records', function (): void {
    $captured = [];
    bindTenantDnsRoutedClient([
        'ListHostedZones' => new Result(['HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'acme.com.au.']]]),
        'ListResourceRecordSets' => new Result(['ResourceRecordSets' => [
            ['Name' => 'acme.com.au.', 'Type' => 'NS', 'ResourceRecords' => [['Value' => 'ns']]],
            ['Name' => 'acme.com.au.', 'Type' => 'SOA', 'ResourceRecords' => [['Value' => 'soa']]],
        ]]),
    ], $captured);

    expect(tenantStep()(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($captured, 'name'))->not->toContain('ChangeResourceRecordSets');
});

/**
 * Uniquely named to avoid colliding with the same-shaped helper in
 * DestroyAppStepsTest.php / TeardownTest.php.
 *
 * @param  array<string, Result>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindTenantDnsRoutedClient(array $byCommand, array &$captured): void
{
    $mock = new class($byCommand, $captured) extends MockHandler
    {
        public function __construct(protected array $byCommand, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $name = $cmd->getName();
            $this->captured[] = ['name' => $name, 'args' => $cmd->toArray()];

            return Create::promiseFor($this->byCommand[$name] ?? new Result());
        }
    };

    Helpers::app()->instance('route53', new Route53Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}
