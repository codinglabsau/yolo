<?php

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Helpers;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Commands\BuildCommand;
use Codinglabs\Yolo\Commands\DeployCommand;
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

/** Three web ingress steps, skipped on a headless app (no domain). */
function headlessGatedSteps(): array
{
    return [
        Steps\Sync\App\SyncTargetGroupStep::class,
        Steps\Sync\App\SyncForwardRuleStep::class,
        Steps\Sync\App\SyncRedirectRuleStep::class,
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

it('constructs every declared step with just the environment string', function (array $manifest): void {
    // Step collation instantiates every declared step as `new $step($environment)`
    // (collateSteps / extractSteps), so a step constructor must take the environment
    // first with everything after it optional. A step taking a different first
    // parameter fatals on the very first run of its command before the plan starts.
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2'] + $manifest);

    $build = new BuildCommand();

    collect((new SyncCommand())->scopes())
        ->flatten()
        ->merge($build->steps())
        ->merge((new ReflectionProperty(BuildCommand::class, 'fargateSteps'))->getValue($build))
        ->merge((new DeployCommand())->steps())
        ->unique()
        ->each(function (string $stepName): void {
            expect(new $stepName('testing'))->toBeInstanceOf(Step::class);
        });
})->with([
    'solo web app' => [['domain' => 'codinglabs.com.au', 'tasks' => ['web' => true]]],
    'multi-tenant app' => [['tenants' => ['alpha' => []]]],
]);

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

it('syncs the standalone queue + scheduler services when both are extracted', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => true], 'scheduler' => true],
    ]);

    $appSteps = (new SyncCommand())->scopes()['app'];

    // The extracting branch is wired; the melt branch is not.
    expect($appSteps)
        ->toContain(Steps\Sync\App\SyncQueueServiceStep::class)
        ->toContain(Steps\Sync\App\SyncSchedulerServiceStep::class)
        ->not->toContain(Steps\Destroy\App\DeregisterQueueAutoscalingStep::class)
        ->not->toContain(Steps\Destroy\App\TeardownQueueServiceStep::class)
        ->not->toContain(Steps\Destroy\App\TeardownSchedulerServiceStep::class);
});

it('melts a standalone queue + scheduler back down when both are switched off', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => true, 'queue' => false, 'scheduler' => false],
    ]);

    $appSteps = (new SyncCommand())->scopes()['app'];

    // Switching a group off must wire its teardown, not just prune the sync steps —
    // otherwise a previously-extracted service is stranded with the plan silent on it.
    expect($appSteps)
        ->toContain(Steps\Destroy\App\DeregisterQueueAutoscalingStep::class)
        ->toContain(Steps\Destroy\App\TeardownQueueServiceStep::class)
        ->toContain(Steps\Destroy\App\TeardownSchedulerServiceStep::class)
        ->not->toContain(Steps\Sync\App\SyncQueueServiceStep::class)
        ->not->toContain(Steps\Sync\App\SyncSchedulerServiceStep::class);
});

it('melts the SQS queue + depth alarm when the queue is disabled (tasks.queue: false)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => true, 'queue' => false],
    ]);

    $appSteps = (new SyncCommand())->scopes()['app'];

    // `queue: false` runs jobs inline (QUEUE_CONNECTION=sync) — the SQS queue is
    // never published to, so it's torn down rather than provisioned idle.
    expect($appSteps)
        ->toContain(Steps\Destroy\App\TeardownQueueAlarmStep::class)
        ->toContain(Steps\Destroy\App\TeardownQueueStep::class)
        ->not->toContain(Steps\Sync\App\Solo\SyncQueueStep::class)
        ->not->toContain(Steps\Sync\App\Solo\SyncQueueAlarmStep::class);
});

it('provisions the SQS queue when the queue runs (bundled into web by default)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => true],
    ]);

    $appSteps = (new SyncCommand())->scopes()['app'];

    expect($appSteps)
        ->toContain(Steps\Sync\App\Solo\SyncQueueStep::class)
        ->toContain(Steps\Sync\App\Solo\SyncQueueAlarmStep::class)
        ->not->toContain(Steps\Destroy\App\TeardownQueueStep::class);
});

it('melts a standalone queue + scheduler back down when the roles revert to bundled', function (): void {
    // No queue/scheduler block at all — the roles ride the web container. An app that
    // previously extracted them must still get the teardown wired so the revert lands.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => true],
    ]);

    $appSteps = (new SyncCommand())->scopes()['app'];

    expect($appSteps)
        ->toContain(Steps\Destroy\App\DeregisterQueueAutoscalingStep::class)
        ->toContain(Steps\Destroy\App\TeardownQueueServiceStep::class)
        ->toContain(Steps\Destroy\App\TeardownSchedulerServiceStep::class)
        ->not->toContain(Steps\Sync\App\SyncQueueServiceStep::class)
        ->not->toContain(Steps\Sync\App\SyncSchedulerServiceStep::class);
});

it('provisions the env bucket before the load balancer in the environment scope', function (): void {
    // `SyncLoadBalancerStep` writes `access_logs.s3.bucket`, which AWS validates
    // against the bucket's log-delivery policy at attribute-write time. The bucket
    // (S3LogsBucket) MUST therefore be provisioned first within the same
    // scope, or a greenfield sync fails at `ModifyLoadBalancerAttributes`.
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    $envSteps = (new SyncCommand())->scopes()['environment'];

    $logBucket = array_search(Steps\Sync\Environment\SyncS3LogsBucketStep::class, $envSteps, true);
    $loadBalancer = array_search(Steps\Sync\Environment\SyncLoadBalancerStep::class, $envSteps, true);

    expect($logBucket)->not->toBeFalse('log-bucket step is registered')
        ->and($loadBalancer)->not->toBeFalse('load-balancer step is registered')
        ->and($logBucket)->toBeLessThan($loadBalancer);
});

it('keys each scope distinctly so no scope is dropped on merge', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => true],
    ]);

    $scopes = array_keys((new SyncCommand())->scopes());

    expect($scopes)->toBe(['account', 'environment', 'app'])
        ->and($scopes)->toEqual(array_unique($scopes));
});

it('groups the three skipped headless-gated steps under a single determination', function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    [$plan, $skipped] = collate(['app' => headlessGatedSteps()]);

    expect($plan)->toHaveCount(0);
    expect($skipped)->toHaveCount(3);

    expect($skipped->groupBy(fn (array $entry): string => $entry['scope'] . '|' . $entry['reason']))
        ->toHaveCount(1);
    expect($skipped->first()['reason'])->toBe('headless app (no ALB / Route 53 / domain)');
});

it('plans the web ingress steps when the app has a domain', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'domain' => 'example.com.au',
    ]);

    [$plan, $skipped] = collate(['app' => headlessGatedSteps()]);

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
