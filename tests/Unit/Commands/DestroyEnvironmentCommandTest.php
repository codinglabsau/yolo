<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Destroying;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Commands\DestroyEnvironmentCommand;

beforeEach(function (): void {
    Helpers::app()->instance('runningInAws', false);
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'example.com',
        'services' => ['typesense'],
        'tasks' => ['web' => true],
    ]);
    Lifecycle::reset();
});

/**
 * @return array<int, class-string>
 */
function destroyEnvPlanClasses(): array
{
    $command = new DestroyEnvironmentCommand();
    $input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    [$plan] = (new ReflectionMethod($command, 'collateSteps'))->invoke($command, $command->scopes(), 'testing');

    return $plan->pluck('step')->map(fn (object $step): string => $step::class)->values()->all();
}

it('forces env-backed services to teardown only for the duration of the run', function (): void {
    expect(Destroying::active())->toBeFalse();

    // The flag short-circuits Lifecycle::state to Teardown even though the manifest
    // still declares the service — and is restored afterwards.
    $state = Destroying::during(fn (): ServiceState => Lifecycle::state(Service::TYPESENSE));

    expect($state)->toBe(ServiceState::Teardown)
        ->and(Destroying::active())->toBeFalse();
});

it('tears the service stacks down before the shared edge, and the env config bucket last', function (): void {
    $classes = destroyEnvPlanClasses();
    $at = fn (string $class): int|false => array_search($class, $classes, true);

    // The Typesense stack comes before the WAF/ALB its rules + target group hang off.
    expect($at(Steps\Sync\Environment\SyncServicesClusterStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\DisassociateWafStep::class));

    // The search listener rule references the target group (rule first); the cluster
    // drains the nodes that target it (cluster first).
    expect($at(Steps\Sync\Environment\SyncSearchListenerRuleStep::class))
        ->toBeLessThan($at(Steps\Sync\Environment\SyncSearchTargetGroupStep::class));
    expect($at(Steps\Sync\Environment\SyncServicesClusterStep::class))
        ->toBeLessThan($at(Steps\Sync\Environment\SyncSearchTargetGroupStep::class));

    // WAF off the ALB, then the listeners, then the ALB, then its security group.
    expect($at(Steps\Destroy\Environment\DisassociateWafStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownLoadBalancerStep::class));
    expect($at(Steps\Destroy\Environment\TeardownHttpsListenerStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownLoadBalancerStep::class));
    expect($at(Steps\Destroy\Environment\TeardownLoadBalancerStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownLoadBalancerSecurityGroupStep::class));

    // Deleting the env config bucket is the final act.
    expect($at(Steps\Destroy\Environment\TeardownEnvConfigBucketStep::class))->toBe(count($classes) - 1);
});

it('tears the Valkey cache cluster down before the groups and SG it pins', function (): void {
    $classes = destroyEnvPlanClasses();
    $at = fn (string $class): int|false => array_search($class, $classes, true);

    expect($at(Steps\Destroy\Environment\TeardownCacheClusterStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownCacheSubnetGroupStep::class))
        ->and($at(Steps\Destroy\Environment\TeardownCacheClusterStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownCacheParameterGroupStep::class))
        ->and($at(Steps\Destroy\Environment\TeardownCacheClusterStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownCacheSecurityGroupStep::class));
});

it('never touches the network shell (Tier B) or the database', function (): void {
    // The cache subnet group is Tier A (YOLO-owned), so match the network-shell
    // resources by their specific names rather than a loose "Subnet" substring.
    foreach (destroyEnvPlanClasses() as $class) {
        expect($class)->not->toContain('Vpc')
            ->not->toContain('PublicSubnet')
            ->not->toContain('RouteTable')
            ->not->toContain('InternetGateway')
            ->not->toContain('RdsSubnet')
            ->not->toContain('RdsSecurityGroup');
    }
});

it('reframes the runner wording as an irreversible destroy', function (): void {
    $command = new DestroyEnvironmentCommand();

    expect((new ReflectionMethod($command, 'planHeading'))->invoke($command))->toBe('Will destroy')
        ->and((new ReflectionMethod($command, 'confirmQuestion'))->invoke($command, 'production'))->toContain('Permanently delete')
        ->and((new ReflectionMethod($command, 'completionVerb'))->invoke($command))->toBe('Destroyed');
});

it('refuses with FAILURE while an app still claims the environment', function (): void {
    // A published claim (apps/foo.yml) means the env is still in use. The guard
    // short-circuits before parent::handle(), so no AWS teardown runs.
    $s3 = [];
    bindRoutedS3Client([
        'ListObjectsV2' => new Result(['Contents' => [['Key' => 'apps/foo.yml']], 'IsTruncated' => false]),
        'GetObject' => new Result(['Body' => "name: foo\nservices: {}\n"]),
    ], $s3);

    $ecs = [];
    bindRoutedEcsClient(['ListClusters' => new Result(['clusterArns' => []])], $ecs);

    Lifecycle::reset();

    $command = new DestroyEnvironmentCommand();
    $input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    expect($command->handle())->toBe(Command::FAILURE)
        ->and(array_column($s3, 'name'))->not->toContain('DeleteBucket');
});
