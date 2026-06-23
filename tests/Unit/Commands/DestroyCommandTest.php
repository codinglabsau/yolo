<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Commands\DestroyCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Helpers::app()->instance('runningInAws', false);
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'example.com',
        'tasks' => ['web' => true],
    ]);
    Lifecycle::reset();
});

/**
 * @return array<int, class-string>
 */
function orchestratorPlanClasses(): array
{
    // A VPC with no attached database — the default path, where the network shell
    // is reclaimed as part of the environment teardown.
    $ec2 = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1', 'Tags' => [['Key' => 'Name', 'Value' => (new Vpc())->name()]]]]]),
    ], $ec2);
    $rds = [];
    bindMockRdsClient(['DescribeDBInstances' => new Result(['DBInstances' => []])], $rds);

    $command = new DestroyCommand();
    $input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    [$plan] = (new ReflectionMethod($command, 'collateSteps'))->invoke($command, $command->scopes(), 'testing');

    return $plan->pluck('step')->map(fn (object $step): string => $step::class)->values()->all();
}

it('orchestrates app → environment → account, stripping the manifest dead last', function (): void {
    $classes = orchestratorPlanClasses();
    $at = fn (string $class): int|false => array_search($class, $classes, true);

    // The app teardown precedes the environment teardown.
    expect($at(Steps\Destroy\App\TeardownWebServiceStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownLoadBalancerStep::class));
    // The env IAM tier runs after the whole network shell — it deletes the role +
    // policy the run is authenticated under, so it can't precede anything needing
    // them (and it runs on base credentials, asserted in DestroyEnvironmentCommandTest).
    expect($at(Steps\Destroy\Environment\TeardownVpcStep::class))
        ->toBeLessThan($at(Steps\Destroy\Environment\TeardownObserverPolicyStep::class));
    // The network shell (env, Tier B) comes before the account-shared provider.
    expect($at(Steps\Destroy\Environment\TeardownVpcStep::class))
        ->toBeLessThan($at(Steps\Destroy\Account\TeardownGithubOidcProviderStep::class));
    // The account provider precedes the manifest strip.
    expect($at(Steps\Destroy\Account\TeardownGithubOidcProviderStep::class))
        ->toBeLessThan($at(Steps\Destroy\App\RemoveEnvironmentFromManifestStep::class));
    // Stripping yolo.yml is the very last step — after everything that still needs
    // the manifest's account/region to resolve.
    expect($at(Steps\Destroy\App\RemoveEnvironmentFromManifestStep::class))->toBe(count($classes) - 1);
    // It appears exactly once — deferred out of the app scope, never duplicated.
    expect(array_keys($classes, Steps\Destroy\App\RemoveEnvironmentFromManifestStep::class, true))->toHaveCount(1);
});

it('always composes the account provider teardown, which self-gates on no other environment', function (): void {
    $classes = orchestratorPlanClasses();
    $at = fn (string $class): int|false => array_search($class, $classes, true);

    // No flag — the account-shared provider step is always planned; it decides at
    // run time whether to delete or keep itself (named in the summary).
    expect($classes)->toContain(Steps\Destroy\Account\TeardownGithubOidcProviderStep::class);
    expect($at(Steps\Destroy\Account\TeardownGithubOidcProviderStep::class))
        ->toBeGreaterThan($at(Steps\Destroy\Environment\TeardownEnvConfigBucketStep::class))
        ->toBeLessThan($at(Steps\Destroy\App\RemoveEnvironmentFromManifestStep::class));
});

it('reframes the runner wording as an irreversible destroy', function (): void {
    $command = new DestroyCommand();

    expect((new ReflectionMethod($command, 'planHeading'))->invoke($command))->toBe('Will destroy')
        ->and((new ReflectionMethod($command, 'confirmQuestion'))->invoke($command, 'production'))->toContain('Permanently delete')
        ->and((new ReflectionMethod($command, 'completionVerb'))->invoke($command))->toBe('Destroyed');
});

it('refuses while another app still claims the environment', function (): void {
    // Two published claims — this app (my-app) and another. The orchestrator destroys
    // my-app in the same run, so it's excused; other-app is not, so it refuses.
    $s3 = [];
    bindRoutedS3Client([
        'ListObjectsV2' => new Result(['Contents' => [['Key' => 'apps/my-app.yml'], ['Key' => 'apps/other-app.yml']], 'IsTruncated' => false]),
        'GetObject' => [
            new Result(['Body' => "name: my-app\nservices: {}\n"]),
            new Result(['Body' => "name: other-app\nservices: {}\n"]),
        ],
    ], $s3);
    $ecs = [];
    bindRoutedEcsClient(['ListClusters' => new Result(['clusterArns' => []])], $ecs);
    Lifecycle::reset();

    $command = new DestroyCommand();
    $input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());
    $input->setInteractive(false);
    $command->input = $input;
    $command->output = new BufferedOutput();

    expect($command->handle())->toBe(Command::FAILURE)
        ->and(array_column($s3, 'name'))->not->toContain('DeleteBucket');
});
