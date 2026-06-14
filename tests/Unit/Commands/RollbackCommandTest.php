<?php

use Aws\Result;
use Carbon\Carbon;
use Codinglabs\Yolo\Yolo;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Commands\RollbackCommand;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Steps\Deploy\UpdateEcsServiceStep;
use Codinglabs\Yolo\Steps\Deploy\ExecuteDeployStepsStep;
use Codinglabs\Yolo\Steps\Deploy\RegisterTaskDefinitionRevisionStep;

/**
 * Drive RollbackCommand::handle() directly with mocked AWS clients. Prompts run
 * non-interactively, so confirm() returns its default (false) — apply paths
 * pass --force. The interactive picker isn't exercised here (rollbackTargets()
 * and targetLabel() are unit-tested directly); every flow passes --app-version
 * to skip it.
 *
 * @param  array<string, string|bool>  $options
 */
function invokeRollback(array $options = [], bool $interactive = true, string $environment = 'testing'): int
{
    Prompt::interactive(false);
    Prompt::setOutput(new BufferedOutput());

    $command = new RollbackCommand();

    $input = ['environment' => $environment];

    foreach ($options as $name => $value) {
        $input['--' . $name] = $value;
    }

    $arrayInput = new ArrayInput($input, $command->getDefinition());
    $arrayInput->setInteractive($interactive);

    $command->input = $arrayInput;
    $command->output = new BufferedOutput();

    return $command->handle();
}

function queueOnlyManifest(): void
{
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['queue' => []],
    ]);
}

/** The ECS reads currentVersion() makes — a queue service running $version. */
function currentlyRunning(string $version): array
{
    return [
        'DescribeServices' => new Result(['services' => [[
            'status' => 'ACTIVE',
            'runningCount' => 1,
            'desiredCount' => 1,
            'deployments' => [[
                'status' => 'PRIMARY',
                'taskDefinition' => 'arn:aws:ecs:ap-southeast-2:111111111111:task-definition/yolo-testing-my-app-queue:9',
            ]],
        ]]]),
        'DescribeTaskDefinition' => new Result(['taskDefinition' => [
            'cpu' => '256',
            'memory' => '512',
            'containerDefinitions' => [[
                'image' => '111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app:' . $version,
            ]],
        ]]),
    ];
}

it('is named rollback', function (): void {
    expect((new RollbackCommand())->getName())->toBe('rollback');
});

it('is registered in the application', function (): void {
    $commands = (new ReflectionClass(Yolo::class))->getDefaultProperties()['commands'];

    expect($commands)->toContain(RollbackCommand::class);
});

it('re-runs the deploy hooks, after registering the revision and before rolling the service', function (): void {
    $steps = (new RollbackCommand())->steps();

    $register = array_search(RegisterTaskDefinitionRevisionStep::class, $steps, true);
    $hooks = array_search(ExecuteDeployStepsStep::class, $steps, true);
    $update = array_search(UpdateEcsServiceStep::class, $steps, true);

    expect($hooks)->not->toBeFalse()
        ->and($register)->toBeLessThan($hooks)
        ->and($hooks)->toBeLessThan($update);
});

it('lists deploy versions newest first, dropping latest/buildcache-only and untagged images', function (): void {
    $images = [
        ['imageTags' => ['26.24.1.0900', 'latest'], 'imagePushedAt' => Carbon::parse('2026-06-13 09:00')],
        ['imageTags' => ['buildcache'], 'imagePushedAt' => Carbon::parse('2026-06-14 10:00')],
        ['imageTags' => ['26.24.2.1130'], 'imagePushedAt' => Carbon::parse('2026-06-14 11:30')],
        ['imagePushedAt' => Carbon::parse('2026-06-12 08:00')],
    ];

    expect(RollbackCommand::rollbackTargets($images))->toBe([
        ['version' => '26.24.2.1130', 'pushedAt' => Carbon::parse('2026-06-14 11:30')->getTimestamp()],
        ['version' => '26.24.1.0900', 'pushedAt' => Carbon::parse('2026-06-13 09:00')->getTimestamp()],
    ]);
});

it('marks the running version as current in the picker label', function (): void {
    $target = ['version' => '26.24.2.1130', 'pushedAt' => Carbon::parse('2026-06-14 11:30')->getTimestamp()];

    expect(RollbackCommand::targetLabel($target, '26.24.2.1130'))->toContain('(current)')
        ->and(RollbackCommand::targetLabel($target, '26.24.1.0900'))->not->toContain('(current)');
});

it('refuses an unknown version and registers nothing', function (): void {
    queueOnlyManifest();

    $ecr = [];
    $ecs = [];
    bindRoutedEcrClient(['DescribeImages' => new Result(['imageDetails' => []])], $ecr);
    bindRoutedEcsClient([], $ecs);

    invokeRollback(options: ['app-version' => '99.99.9.9999', 'force' => true]);

    expect(collect($ecs)->where('name', 'RegisterTaskDefinition'))->toBeEmpty();
});

it('errors when run non-interactively without --app-version', function (): void {
    queueOnlyManifest();

    $ecs = [];
    bindRoutedEcsClient([], $ecs);

    invokeRollback(options: [], interactive: false);

    expect(collect($ecs)->where('name', 'RegisterTaskDefinition'))->toBeEmpty();
});

it('no-ops when the chosen version is already running', function (): void {
    queueOnlyManifest();

    $ecr = [];
    $ecs = [];
    bindRoutedEcrClient(['DescribeImages' => new Result(['imageDetails' => [['imageTag' => '26.24.2.1130']]])], $ecr);
    bindRoutedEcsClient(currentlyRunning('26.24.2.1130'), $ecs);

    invokeRollback(options: ['app-version' => '26.24.2.1130', 'force' => true]);

    expect(collect($ecs)->where('name', 'RegisterTaskDefinition'))->toBeEmpty();
});

it('makes no changes when the rollback is declined at the confirm gate', function (): void {
    queueOnlyManifest();

    $ecr = [];
    $ecs = [];
    bindRoutedEcrClient(['DescribeImages' => new Result(['imageDetails' => [['imageTag' => '26.24.1.0900']]])], $ecr);
    bindRoutedEcsClient(currentlyRunning('26.24.2.1130'), $ecs);

    // No --force, and Prompt::interactive(false) makes confirm() return its
    // default (false), so the gate declines.
    invokeRollback(options: ['app-version' => '26.24.1.0900']);

    expect(collect($ecs)->where('name', 'RegisterTaskDefinition'))->toBeEmpty();
});

it('pins a task-def revision to the chosen version and rolls the service', function (): void {
    queueOnlyManifest();

    // The stepped tail consults Aws::runningInAws() to gate execution context.
    Helpers::app()->instance('runningInAws', false);

    $ecr = [];
    $ecs = [];
    $autoScaling = [];

    bindRoutedEcrClient(['DescribeImages' => new Result(['imageDetails' => [['imageTag' => '26.24.1.0900']]])], $ecr);
    bindRoutedEcsClient([
        ...currentlyRunning('26.24.2.1130'),
        'RegisterTaskDefinition' => new Result([]),
        'UpdateService' => new Result([]),
    ], $ecs);
    bindMockIamClient([
        (new EcsTaskRole())->name() => 'arn:aws:iam::111111111111:role/' . (new EcsTaskRole())->name(),
        (new EcsExecutionRole())->name() => 'arn:aws:iam::111111111111:role/' . (new EcsExecutionRole())->name(),
    ]);
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => []])], $autoScaling);

    $exit = invokeRollback(options: ['app-version' => '26.24.1.0900', 'force' => true]);

    expect($exit)->toBe(0);

    $register = collect($ecs)->firstWhere('name', 'RegisterTaskDefinition');

    expect($register)->not->toBeNull()
        ->and($register['args']['containerDefinitions'][0]['image'])->toEndWith(':26.24.1.0900')
        ->and(collect($ecs)->where('name', 'UpdateService'))->not->toBeEmpty();
});
