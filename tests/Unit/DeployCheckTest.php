<?php

declare(strict_types=1);

use Codinglabs\Yolo\DeployCheck;
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncVpcStep;
use Codinglabs\Yolo\Concerns\ChecksIfCommandsShouldBeRunning;
use Codinglabs\Yolo\Steps\Sync\Environment\BuildTypesenseImageStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseAdminKeyStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseLogGroupStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncTypesenseTaskDefinitionStep;

function deployCheckChecker(): object
{
    return new class()
    {
        use ChecksIfCommandsShouldBeRunning;
    };
}

it('marks the deployer-fenced typesense env steps as skipped-by-deploy-check', function (string $step): void {
    expect(is_subclass_of($step, SkippedByDeployCheck::class))->toBeTrue();
})->with([
    SyncTypesenseAdminKeyStep::class,
    BuildTypesenseImageStep::class,
    SyncTypesenseTaskDefinitionStep::class,
    SyncTypesenseLogGroupStep::class,
]);

it('skips a SkippedByDeployCheck step only while the deploy gate is checking', function (): void {
    $checker = deployCheckChecker();
    $step = new SyncTypesenseAdminKeyStep();

    // Outside the gate, the step runs as normal.
    expect($checker->skipReason($step))->toBeNull();

    // Inside the gate's --check, it's skipped with a reason pointing at `yolo sync`.
    DeployCheck::during(function () use ($checker, $step): void {
        expect($checker->skipReason($step))->toContain('yolo sync');
    });

    // The flag is restored afterwards.
    expect(DeployCheck::active())->toBeFalse()
        ->and($checker->skipReason($step))->toBeNull();
});

it('does not skip an unmarked foundation step inside the gate check', function (): void {
    $checker = deployCheckChecker();

    DeployCheck::during(function () use ($checker): void {
        expect($checker->skipReason(new SyncVpcStep()))->toBeNull();
    });
});
