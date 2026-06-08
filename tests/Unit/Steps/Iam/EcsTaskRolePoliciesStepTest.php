<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\AttachEcsTaskRolePoliciesStep;

const TASK_ROLE = 'yolo-testing-my-app-ecs-task-role';
const BASELINE_POLICY = 'arn:aws:iam::111111111111:policy/yolo-testing-my-app-ecs-task-policy';
const EXTRA_POLICY = 'arn:aws:iam::aws:policy/AmazonS3ReadOnlyAccess';

/** A live attachment set on the task role. */
function attachedPolicies(array $arns): Result
{
    return new Result(['AttachedPolicies' => array_map(
        fn (string $arn): array => ['PolicyName' => basename($arn), 'PolicyArn' => $arn],
        $arns,
    )]);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
        'task-role-policies' => [EXTRA_POLICY],
    ]);
});

it('attaches the baseline policy plus the manifest-declared policies', function (): void {
    $captured = [];
    bindRoutedIamClient(['ListAttachedRolePolicies' => attachedPolicies([])], $captured);

    expect((new AttachEcsTaskRolePoliciesStep())([]))->toBe(StepResult::SYNCED);

    $attached = collect($captured)->where('name', 'AttachRolePolicy')->pluck('args.PolicyArn')->all();
    expect($attached)->toEqualCanonicalizing([BASELINE_POLICY, EXTRA_POLICY]);
    expect(collect($captured)->firstWhere('args.RoleName', TASK_ROLE))->not->toBeNull();
    expect(array_column($captured, 'name'))->not->toContain('DetachRolePolicy');
});

it('reports synced and touches nothing when the attachments already match', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'ListAttachedRolePolicies' => attachedPolicies([BASELINE_POLICY, EXTRA_POLICY]),
    ], $captured);

    expect((new AttachEcsTaskRolePoliciesStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))
        ->not->toContain('AttachRolePolicy')
        ->not->toContain('DetachRolePolicy');
});

it('detaches a policy dropped from the manifest, never the baseline', function (): void {
    // The manifest no longer lists EXTRA_POLICY, but it's still attached — reconcile
    // must detach it (and leave the baseline alone).
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    $captured = [];
    bindRoutedIamClient([
        'ListAttachedRolePolicies' => attachedPolicies([BASELINE_POLICY, EXTRA_POLICY]),
    ], $captured);

    expect((new AttachEcsTaskRolePoliciesStep())([]))->toBe(StepResult::SYNCED);

    $detached = collect($captured)->where('name', 'DetachRolePolicy')->pluck('args.PolicyArn')->all();
    expect($detached)->toBe([EXTRA_POLICY]);
    expect(array_column($captured, 'name'))->not->toContain('AttachRolePolicy');
});

it('plans attach + detach on a dry-run without writing IAM', function (): void {
    // Baseline missing (needs attach) and a stale policy attached (needs detach).
    $captured = [];
    bindRoutedIamClient([
        'ListAttachedRolePolicies' => attachedPolicies(['arn:aws:iam::aws:policy/AdministratorAccess']),
    ], $captured);

    expect((new AttachEcsTaskRolePoliciesStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect(array_column($captured, 'name'))
        ->not->toContain('AttachRolePolicy')
        ->not->toContain('DetachRolePolicy');
});
