<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Destroying;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;
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
 * Bind a VPC with no databases attached — the default destroy path, where the
 * network shell (Tier B) is reclaimed.
 */
function bindEnvironmentWithoutDatabase(): void
{
    $ec2 = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => (new Vpc())->name()]]]]]),
    ], $ec2);
    $rds = [];
    bindMockRdsClient(['DescribeDBInstances' => new Result(['DBInstances' => []])], $rds);
}

/**
 * @return array<int, class-string>
 */
function destroyEnvPlanClasses(): array
{
    bindEnvironmentWithoutDatabase();

    $command = new DestroyEnvironmentCommand();
    $input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    [$plan] = (new ReflectionMethod($command, 'collateSteps'))->invoke($command, $command->scopes(), 'testing');

    return $plan->pluck('step')->map(fn (object $step): string => $step::class)->values()->all();
}

it('reclaims the network shell when no database is attached', function (): void {
    $classes = destroyEnvPlanClasses();
    $at = fn (string $class): int|false => array_search($class, $classes, true);

    expect($classes)->toContain(Steps\Destroy\Environment\TeardownVpcStep::class)
        ->toContain(Steps\Destroy\Environment\TeardownRdsSubnetStep::class);
    // RDS subnet group + SG go before the subnets.
    expect($at(Steps\Destroy\Environment\TeardownRdsSubnetStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownPublicSubnetAStep::class));
    // The whole network shell (VPC is the last of Tier B) tears down before the IAM
    // tier, which runs dead last on base credentials — it deletes the role + policy
    // the run is authenticated under, so it can't precede anything that needs them.
    expect($at(Steps\Destroy\Environment\TeardownVpcStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownEcsExecutionRoleStep::class));
    expect($at(Steps\Destroy\Environment\TeardownObserverPolicyStep::class))->toBe(count($classes) - 1);
});

it('runs every IAM-tier teardown step on base credentials (it deletes the tier it assumed)', function (): void {
    $classes = destroyEnvPlanClasses();

    $iamTier = DestroyEnvironmentCommand::iamTierTeardownSteps();

    // Every IAM-tier step is marked RunsOnBaseCredentials so the runner drops the
    // assumed admin credentials before deleting the role + policy that grant them.
    foreach ($iamTier as $stepClass) {
        expect(new $stepClass('testing'))->toBeInstanceOf(RunsOnBaseCredentials::class);
    }

    // And they are the final steps of the plan, after the network.
    $at = fn (string $class): int|false => array_search($class, $classes, true);
    expect($at($iamTier[0]))->toBeGreaterThan($at(Steps\Destroy\Environment\TeardownVpcStep::class));
});

it('refuses the network reclaim while a database is attached, naming it in the summary', function (): void {
    $ec2 = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => (new Vpc())->name()]]]]]),
    ], $ec2);
    $rds = [];
    bindMockRdsClient([
        'DescribeDBInstances' => new Result(['DBInstances' => [
            ['DBInstanceIdentifier' => 'codinglabs', 'DBSubnetGroup' => ['VpcId' => 'vpc-1']],
        ]]),
    ], $rds);

    $command = new DestroyEnvironmentCommand();
    $input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    $classes = (new ReflectionMethod($command, 'collateSteps'))->invoke($command, $command->scopes(), 'testing')[0]
        ->pluck('step')->map(fn (object $step): string => $step::class)->values()->all();

    // The network shell is left standing — no Tier-B steps composed.
    expect($classes)->not->toContain(Steps\Destroy\Environment\TeardownVpcStep::class);
    // The refusal names the blocking database.
    expect(implode(' ', $command->warnings()))
        ->toContain('codinglabs')
        ->toContain('Refusing to reclaim the network shell');
});

it('reclaims the network shell when the only database is in a different VPC', function (): void {
    $ec2 = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => (new Vpc())->name()]]]]]),
    ], $ec2);
    $rds = [];
    bindMockRdsClient([
        // A live database, but in a different VPC — it does NOT pin this env's
        // network, so it must not block the reclaim.
        'DescribeDBInstances' => new Result(['DBInstances' => [
            ['DBInstanceIdentifier' => 'someone-else', 'DBSubnetGroup' => ['VpcId' => 'vpc-other']],
        ]]),
    ], $rds);

    $command = new DestroyEnvironmentCommand();
    $input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    $classes = (new ReflectionMethod($command, 'collateSteps'))->invoke($command, $command->scopes(), 'testing')[0]
        ->pluck('step')->map(fn (object $step): string => $step::class)->values()->all();

    expect($classes)->toContain(Steps\Destroy\Environment\TeardownVpcStep::class)
        ->and($command->warnings())->toBe([]);
});

it('forces env-backed services to teardown only for the duration of the run', function (): void {
    expect(Destroying::active())->toBeFalse();

    // The flag short-circuits Lifecycle::state to Teardown even though the manifest
    // still declares the service — and is restored afterwards.
    $state = Destroying::during(fn (): ServiceState => Lifecycle::state(Service::TYPESENSE));

    expect($state)->toBe(ServiceState::Teardown)
        ->and(Destroying::active())->toBeFalse();
});

it('tears the service stacks down before the shared edge, and the env config bucket before the network', function (): void {
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

    // The env config bucket (Tier A's last) is deleted before the network shell goes.
    expect($at(Steps\Destroy\Environment\TeardownEnvConfigBucketStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownVpcStep::class));
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
