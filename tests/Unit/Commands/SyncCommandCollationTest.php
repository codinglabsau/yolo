<?php

use Codinglabs\Yolo\Steps;
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

beforeEach(function (): void {
    Helpers::app()->instance('runningInAws', false);
});

/**
 * @param  array<string, array<int, class-string>>  $scopes
 * @return array{0: Collection, 1: Collection} [$plan, $skipped]
 */
function collate(array $scopes, ?SyncSteppedCommand $command = null, array $options = []): array
{
    $command ??= new SyncCommand();

    $input = new ArrayInput(['environment' => 'testing'] + $options, $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    return (new ReflectionMethod($command, 'collateSteps'))->invoke($command, $scopes, 'testing');
}

/** The three IVS steps, skipped unless ivs is enabled. */
function ivsSteps(): array
{
    return [
        Steps\Sync\App\SyncIvsCloudWatchLogGroupStep::class,
        Steps\Sync\App\SyncIvsEventBridgeRuleStep::class,
        Steps\Sync\App\SyncIvsEventBridgeTargetStep::class,
    ];
}

/** A per-tenant step that needs no AWS — to exercise the fan-out / --tenant filter. */
class CollationFakeTenantStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        return StepResult::CREATED;
    }
}

it('orchestrates the three scopes in order — account → environment → app', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect(array_keys((new SyncCommand())->scopes()))->toBe(['account', 'environment', 'app']);
});

it('folds the Fargate + CDN steps into the app scope when a web task is declared', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => ['cpu' => 512, 'memory' => 1024]],
    ]);

    $appSteps = (new SyncCommand())->scopes()['app'];

    expect($appSteps)->toContain(Steps\Sync\App\SyncEcsServiceStep::class)
        ->and($appSteps)->toContain(Steps\Sync\App\SyncAssetDistributionStep::class);
});

it('omits the Fargate + CDN steps from a solo app with no web task', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    $appSteps = (new SyncCommand())->scopes()['app'];

    expect($appSteps)->not->toContain(Steps\Sync\App\SyncEcsServiceStep::class)
        ->and($appSteps)->toContain(Steps\Sync\App\Solo\SyncHostedZoneStep::class);
});

it('swaps the Solo steps for Landlord + Tenant steps on a multi-tenant app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tenants' => ['alpha' => []],
    ]);

    $appSteps = (new SyncCommand())->scopes()['app'];

    expect($appSteps)->toContain(Steps\Sync\App\Landlord\SyncQueueStep::class)
        ->and($appSteps)->toContain(Steps\Sync\App\Tenant\SyncQueueStep::class)
        ->and($appSteps)->not->toContain(Steps\Sync\App\Solo\SyncHostedZoneStep::class);
});

it('provisions the ALB log bucket before the load balancer in the environment scope', function (): void {
    // `SyncLoadBalancerStep` writes `access_logs.s3.bucket`, which AWS validates
    // against the bucket's log-delivery policy at attribute-write time. The bucket
    // (S3LoadBalancerLogs) MUST therefore be provisioned first within the same
    // scope, or a greenfield sync fails at `ModifyLoadBalancerAttributes`.
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    $envSteps = (new SyncCommand())->scopes()['environment'];

    $logBucket = array_search(Steps\Sync\Environment\SyncS3LoadBalancerLogsStep::class, $envSteps, true);
    $loadBalancer = array_search(Steps\Sync\Environment\SyncLoadBalancerStep::class, $envSteps, true);

    expect($logBucket)->not->toBeFalse('log-bucket step is registered')
        ->and($loadBalancer)->not->toBeFalse('load-balancer step is registered')
        ->and($logBucket)->toBeLessThan($loadBalancer);
});

it('keys each scope distinctly so no scope is dropped on merge', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => []],
    ]);

    $scopes = array_keys((new SyncCommand())->scopes());

    expect($scopes)->toBe(['account', 'environment', 'app'])
        ->and($scopes)->toEqual(array_unique($scopes));
});

it('groups the three skipped IVS steps under a single determination', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    [$plan, $skipped] = collate(['app' => ivsSteps()]);

    expect($plan)->toHaveCount(0);
    expect($skipped)->toHaveCount(3);

    expect($skipped->groupBy(fn (array $entry): string => $entry['scope'] . '|' . $entry['reason']))
        ->toHaveCount(1);
    expect($skipped->first()['reason'])->toBe('ivs not enabled in manifest');
});

it('plans the IVS steps when ivs is enabled', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'ivs' => true,
    ]);

    [$plan, $skipped] = collate(['app' => ivsSteps()]);

    expect($plan)->toHaveCount(3);
    expect($skipped)->toHaveCount(0);
});

it('fans a per-tenant step out across every tenant by default', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tenants' => ['alpha' => [], 'beta' => []],
    ]);

    [$plan] = collate(['app' => [CollationFakeTenantStep::class]], new SyncAppCommand());

    expect($plan->pluck('step')->map->tenantId()->all())->toBe(['alpha', 'beta']);
});

it('narrows the per-tenant fan-out to a single tenant with --tenant', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tenants' => ['alpha' => [], 'beta' => []],
    ]);

    [$plan] = collate(['app' => [CollationFakeTenantStep::class]], new SyncAppCommand(), ['--tenant' => 'alpha']);

    expect($plan)->toHaveCount(1);
    expect($plan->first()['step']->tenantId())->toBe('alpha');
});

it('errors on an unknown --tenant id', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tenants' => ['alpha' => []],
    ]);

    collate(['app' => [CollationFakeTenantStep::class]], new SyncAppCommand(), ['--tenant' => 'ghost']);
})->throws(IntegrityCheckException::class, 'Unknown tenant "ghost"');
