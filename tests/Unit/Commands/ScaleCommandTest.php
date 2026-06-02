<?php

use Aws\Result;
use Codinglabs\Yolo\Yolo;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Commands\ScaleCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Drive ScaleCommand::handle() directly with mocked AWS clients. Prompts run
 * non-interactively: confirm() returns its default (true for raises/applies,
 * false for the reduction guard), and a passed count argument keeps resolveCount
 * away from the text() prompt.
 *
 * @param  array<string, string>  $arguments
 * @param  array<string, string|bool>  $options
 */
function invokeScale(array $arguments = [], array $options = [], string $environment = 'testing'): void
{
    Prompt::interactive(false);
    Prompt::setOutput(new BufferedOutput());

    $command = new ScaleCommand();

    $input = ['environment' => $environment, ...$arguments];

    foreach ($options as $name => $value) {
        $input['--' . $name] = $value;
    }

    $command->input = new ArrayInput($input, $command->getDefinition());
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

it('shows desired count current → new for a fixed service', function () {
    expect(ScaleCommand::desiredCountRows(currentDesired: 1, running: 1, new: 3))->toBe([
        ['Desired count', '1', '3'],
        ['Running', '1', '—'],
    ]);
});

it('shows min/max and an autoscaling-managed desired count for the bounds path', function () {
    expect(ScaleCommand::boundsRows(live: ['min' => 1, 'max' => 6], newMin: 3, newMax: 10))->toBe([
        ['Min capacity', '1', '3'],
        ['Max capacity', '6', '10'],
        ['Desired count', '— (autoscaling-managed)', '—'],
    ]);
});

it('errors on --scheduler without touching AWS', function () {
    invokeScale(options: ['scheduler' => true]);
})->throwsNoExceptions();

it('queue: writes tasks.queue bounds and registers, allowing a zero floor', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'desiredCount' => 0, 'runningCount' => 0]]])], $ecs);
    bindMockApplicationAutoScalingClient([
        // Green-field queue — no target registered yet, so setting a zero floor
        // isn't a reduction and applies straight through.
        'DescribeScalableTargets' => new Result(['ScalableTargets' => []]),
        'RegisterScalableTarget' => new Result([]),
    ], $aa);

    invokeScale(options: ['queue' => true, 'min' => '0', 'max' => '20']);

    expect(collect($aa)->firstWhere('name', 'RegisterScalableTarget')['args'])->toMatchArray(['MinCapacity' => 0, 'MaxCapacity' => 20]);
    expect(Manifest::get('tasks.queue.min'))->toBe(0);
    expect(Manifest::get('tasks.queue.max'))->toBe(20);
});

it('queue: rejects a fixed desired count (always autoscaling-managed)', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'desiredCount' => 0, 'runningCount' => 0]]])], $ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => []])], $aa);

    invokeScale(arguments: ['count' => '3'], options: ['queue' => true]);

    expect(collect($ecs)->pluck('name'))->not->toContain('UpdateService');
});

it('fixed: sets the ECS desired count directly when no scalable target exists', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient([
        'DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'desiredCount' => 1, 'runningCount' => 1]]]),
        'UpdateService' => new Result([]),
    ], $ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => []])], $aa);

    invokeScale(arguments: ['count' => '3']);

    expect(collect($ecs)->firstWhere('name', 'UpdateService')['args'])->toMatchArray(['desiredCount' => 3]);
    expect(collect($aa)->pluck('name'))->not->toContain('RegisterScalableTarget');
});

it('bounds: writes the manifest and registers when raising', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 4]]],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'desiredCount' => 1, 'runningCount' => 1]]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 1, 'MaxCapacity' => 4]]]),
        'RegisterScalableTarget' => new Result([]),
    ], $aa);

    invokeScale(options: ['web' => true, 'min' => '3', 'max' => '10']);

    expect(collect($aa)->firstWhere('name', 'RegisterScalableTarget')['args'])->toMatchArray(['MinCapacity' => 3, 'MaxCapacity' => 10]);
    expect(Manifest::get('tasks.web.autoscaling.min'))->toBe(3);
    expect(Manifest::get('tasks.web.autoscaling.max'))->toBe(10);
});

it('bounds: does not reduce without confirmation', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 5, 'max' => 10]]],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'desiredCount' => 5, 'runningCount' => 5]]])], $ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 5, 'MaxCapacity' => 10]]])], $aa);

    // Non-interactive confirm returns the reduction guard's default (false) → bails.
    invokeScale(options: ['web' => true, 'min' => '2']);

    expect(collect($aa)->pluck('name'))->not->toContain('RegisterScalableTarget');
    expect(Manifest::get('tasks.web.autoscaling.min'))->toBe(5);
});

it('rejects a desired count on an autoscaling-managed service', function () {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 4]]],
    ]);

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'desiredCount' => 1, 'runningCount' => 1]]])], $ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 1, 'MaxCapacity' => 4]]])], $aa);

    invokeScale(arguments: ['count' => '3'], options: ['web' => true]);

    expect(collect($ecs)->pluck('name'))->not->toContain('UpdateService');
    expect(collect($aa)->pluck('name'))->not->toContain('RegisterScalableTarget');
});
