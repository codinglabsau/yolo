<?php

use Aws\Result;
use Codinglabs\Yolo\Yolo;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Commands\ScaleCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Drive ScaleCommand::handle() directly with mocked AWS clients. Prompts run
 * non-interactively (confirm returns its default `true`), and the count is passed
 * as an argument so resolveCount() never reaches the text() prompt.
 */
function invokeScale(?string $count = null, string $environment = 'testing'): void
{
    Prompt::interactive(false);
    Prompt::setOutput(new BufferedOutput());

    $command = new ScaleCommand();
    $arguments = ['environment' => $environment];

    if ($count !== null) {
        $arguments['count'] = $count;
    }

    $command->input = new ArrayInput($arguments, $command->getDefinition());
    $command->output = new BufferedOutput();
    $command->handle();
}

it('is named scale', function () {
    expect((new ScaleCommand())->getName())->toBe('scale');
});

it('is registered in the application', function () {
    $commands = (new ReflectionClass(Yolo::class))->getDefaultProperties()['commands'];

    expect($commands)->toContain(ScaleCommand::class);
});

it('compares desired count when the service is not autoscaling-managed', function () {
    expect(ScaleCommand::rows(managed: false, currentDesired: 1, running: 1, live: null, new: 3))->toBe([
        ['Desired count', '1', '3'],
        ['Running', '1', '—'],
    ]);
});

it('compares minimum capacity and marks desired count autoscaling-managed when managed', function () {
    expect(ScaleCommand::rows(managed: true, currentDesired: 2, running: 2, live: ['min' => 1, 'max' => 6], new: 3))->toBe([
        ['Min capacity', '1', '3'],
        ['Desired count', '— (autoscaling-managed)', '—'],
        ['Running', '2', '—'],
    ]);
});

it('managed: raises the minimum capacity and lifts the ceiling when scaling above max', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 4]]],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'desiredCount' => 1, 'runningCount' => 1]]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 1, 'MaxCapacity' => 4]]]),
        'RegisterScalableTarget' => new Result([]),
    ], $aa);

    invokeScale('6'); // above the current max of 4

    $register = collect($aa)->firstWhere('name', 'RegisterScalableTarget');
    expect($register['args'])->toMatchArray(['MinCapacity' => 6, 'MaxCapacity' => 6]);
    expect(collect($ecs)->pluck('name'))->not->toContain('UpdateService');
});

it('managed: preserves the existing ceiling when scaling within it', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 6]]],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'desiredCount' => 2, 'runningCount' => 2]]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 1, 'MaxCapacity' => 6]]]),
        'RegisterScalableTarget' => new Result([]),
    ], $aa);

    invokeScale('3');

    $register = collect($aa)->firstWhere('name', 'RegisterScalableTarget');
    expect($register['args'])->toMatchArray(['MinCapacity' => 3, 'MaxCapacity' => 6]);
});

it('unmanaged: sets the ECS desired count directly when no scalable target exists', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => []],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient([
        'DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'desiredCount' => 1, 'runningCount' => 1]]]),
        'UpdateService' => new Result([]),
    ], $ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => []])], $aa);

    invokeScale('3');

    $update = collect($ecs)->firstWhere('name', 'UpdateService');
    expect($update['args'])->toMatchArray(['desiredCount' => 3]);
    expect(collect($aa)->pluck('name'))->not->toContain('RegisterScalableTarget');
});

it('errors and makes no change when the web service is not found', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => []],
    ]);

    $ecs = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => []])], $ecs);

    invokeScale('3');

    expect(collect($ecs)->pluck('name'))->not->toContain('UpdateService');
});
