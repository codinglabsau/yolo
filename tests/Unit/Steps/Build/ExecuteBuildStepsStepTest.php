<?php

declare(strict_types=1);

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\BuildCommand;
use Codinglabs\Yolo\Steps\ExecuteBuildCommandStep;
use Codinglabs\Yolo\Steps\Build\ExecuteBuildStepsStep;

/**
 * Expand ExecuteBuildStepsStep through the runner's protected expandStep() — the
 * site where a null sub-step list fatals array_map().
 *
 * @return array<int, object>
 */
function expandBuildSteps(): array
{
    $command = new BuildCommand();

    return (new ReflectionMethod($command, 'expandStep'))
        ->invoke($command, new ExecuteBuildStepsStep(), 'testing');
}

it('lists the manifest build commands as its sub-steps', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'build' => ['composer install', 'npm run build'],
    ]);

    expect((new ExecuteBuildStepsStep())->subSteps())->toBe(['composer install', 'npm run build']);
});

it('expands into the parent step plus one command step per build hook', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'build' => ['composer install', 'npm run build'],
    ]);

    $expanded = expandBuildSteps();

    expect($expanded)->toHaveCount(3)
        ->and($expanded[0])->toBeInstanceOf(ExecuteBuildStepsStep::class)
        ->and($expanded[1])->toBeInstanceOf(ExecuteBuildCommandStep::class)
        ->and($expanded[2])->toBeInstanceOf(ExecuteBuildCommandStep::class);
});

it('expands to just the parent step (no fatal) when the manifest has no build key', function (): void {
    // Regression guard: `build:` is optional. subSteps() used to return null here,
    // and expandStep's array_map(null) fataled — `yolo build` broke for build-less apps.
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    expect((new ExecuteBuildStepsStep())->subSteps())->toBe([]);

    $expanded = expandBuildSteps();

    expect($expanded)->toHaveCount(1)
        ->and($expanded[0])->toBeInstanceOf(ExecuteBuildStepsStep::class);
});

it('is a no-op returning SUCCESS when run directly', function (): void {
    expect((new ExecuteBuildStepsStep())())->toBe(StepResult::SUCCESS);
});
