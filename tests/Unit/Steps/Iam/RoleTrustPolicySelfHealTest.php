<?php

use Aws\Result;
use Illuminate\Support\Collection;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Resources\Iam\MediaConvertRole;
use Codinglabs\Yolo\Steps\Sync\App\SyncEcsTaskRoleStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncMediaConvertRoleStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncEcsExecutionRoleStep;

/**
 * The service-principal roles (ECS task, ECS execution, MediaConvert) reconcile
 * their trust the same way the deployer role does, through
 * SynchronisesConfiguration. These guard the generic (sub-less) drift branch and
 * confirm each step records drift on the plan pass — so the only-pending-steps
 * filter can't prune the self-heal — and stays quiet when the trust is in sync.
 */
dataset('serviceRoles', [
    'ecs task role' => [EcsTaskRole::class, SyncEcsTaskRoleStep::class, []],
    'ecs execution role' => [EcsExecutionRole::class, SyncEcsExecutionRoleStep::class, []],
    'mediaconvert role' => [MediaConvertRole::class, SyncMediaConvertRoleStep::class, ['mediaconvert' => true]],
]);

function bindServiceRoleTrust(string $roleName, array $liveTrust, array &$captured): void
{
    bindRoutedIamClient([
        'ListRoles' => new Result(['Roles' => [[
            'RoleName' => $roleName,
            'Arn' => 'arn:aws:iam::111111111111:role/' . $roleName,
            // IAM returns the live trust URL-encoded on the role record.
            'AssumeRolePolicyDocument' => rawurlencode(json_encode($liveTrust)),
        ]]]),
    ], $captured);
}

function trustChangesFor(object $step): Collection
{
    return collect($step->changes())->filter(fn ($change) => str_contains($change->attribute, 'trust'));
}

it('records trust drift on the plan pass and rewrites it on apply', function (string $resourceClass, string $stepClass, array $manifestExtra) {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', ...$manifestExtra]);

    $resource = new $resourceClass();

    // Drift the live trust's principal away from the rendered desired.
    $drifted = $resource->assumeRolePolicyDocument();
    $drifted['Statement'][0]['Principal']['Service'] = 'stale.amazonaws.com';

    // Plan (dry-run) pass: the drift is recorded so the step survives the prune,
    // but the trust is never rewritten.
    $captured = [];
    bindServiceRoleTrust($resource->name(), $drifted, $captured);

    $planStep = new $stepClass();
    expect($planStep(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect(trustChangesFor($planStep))->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('UpdateAssumeRolePolicy');

    // Apply pass: the trust is reconciled in place.
    $captured = [];
    bindServiceRoleTrust($resource->name(), $drifted, $captured);

    (new $stepClass())([]);
    expect(array_column($captured, 'name'))->toContain('UpdateAssumeRolePolicy');
})->with('serviceRoles');

it('records no trust change and never rewrites when the trust already matches', function (string $resourceClass, string $stepClass, array $manifestExtra) {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', ...$manifestExtra]);

    $resource = new $resourceClass();

    $captured = [];
    bindServiceRoleTrust($resource->name(), $resource->assumeRolePolicyDocument(), $captured);

    $step = new $stepClass();
    $step([]);

    // An in-sync trust produces no pending entry, so a no-op sync stays quiet and
    // the confirm gate can clear.
    expect(trustChangesFor($step))->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('UpdateAssumeRolePolicy');
})->with('serviceRoles');
