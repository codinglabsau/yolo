<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncMediaConvertRoleStep;

it('skips when the app never claimed mediaconvert and no role exists', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    bindMockIamClient([]);

    expect((new SyncMediaConvertRoleStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});

it('melts the role away when the app drops its mediaconvert claim', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    $roleArn = 'arn:aws:iam::111111111111:role/yolo-testing-my-app-mediaconvert-role';

    $captured = [];
    bindRoutedIamClient([
        'ListRoles' => new Result([
            'Roles' => [['RoleName' => 'yolo-testing-my-app-mediaconvert-role', 'Arn' => $roleArn]],
            'IsTruncated' => false,
        ]),
        'ListAttachedRolePolicies' => new Result([
            'AttachedPolicies' => [['PolicyArn' => 'arn:aws:iam::aws:policy/AmazonS3FullAccess']],
        ]),
    ], $captured);

    // Plan: the unclaimed-but-existing role is drift — recorded, not written.
    $planned = new SyncMediaConvertRoleStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE);
    expect($planned->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('DeleteRole');

    // Apply: attachments detach first, then the role goes.
    expect((new SyncMediaConvertRoleStep())([]))->toBe(StepResult::DELETED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('DetachRolePolicy')->toContain('DeleteRole');
    expect(array_search('DetachRolePolicy', $names))->toBeLessThan(array_search('DeleteRole', $names));
});
