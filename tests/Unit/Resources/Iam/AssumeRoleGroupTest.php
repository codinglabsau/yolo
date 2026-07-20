<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\AdminsGroup;
use Codinglabs\Yolo\Resources\Iam\DeployersGroup;
use Codinglabs\Yolo\Resources\Iam\ObserversGroup;
use Codinglabs\Yolo\Resources\Iam\AssumeRoleGroup;
use Codinglabs\Yolo\Resources\Iam\AppObserversGroup;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('names each grant group and scopes it correctly', function (AssumeRoleGroup $group, Scope $scope, string $name): void {
    expect($group->scope())->toBe($scope);
    expect($group->name())->toBe($name);
})->with([
    'env observers' => [fn (): AssumeRoleGroup => new ObserversGroup(), Scope::Env, 'yolo-testing-observers'],
    'app observers' => [fn (): AssumeRoleGroup => new AppObserversGroup(), Scope::App, 'yolo-testing-my-app-observers'],
    'app deployers' => [fn (): AssumeRoleGroup => new DeployersGroup(), Scope::App, 'yolo-testing-my-app-deployers'],
    'env admins' => [fn (): AssumeRoleGroup => new AdminsGroup(), Scope::Env, 'yolo-testing-admins'],
]);

it('grants sts:AssumeRole on exactly its tier role plus the self-service credential slice, built purely from the manifest', function (AssumeRoleGroup $group, string|array $roleArn): void {
    $document = $group->document();
    $self = [
        'arn:aws:iam::111111111111:user/${aws:username}',
        'arn:aws:iam::111111111111:mfa/*',
    ];

    expect($document['Version'])->toBe('2012-10-17');
    expect($document['Statement'])->toHaveCount(4);

    $assumeRole = $document['Statement'][0];
    expect($assumeRole['Effect'])->toBe('Allow');
    expect($assumeRole['Action'])->toBe('sts:AssumeRole');
    expect($assumeRole['Resource'])->toBe($roleArn);

    // The MFA bootstrap path — ungated so a new user can enrol their first
    // device, scoped to the member's own user/mfa ARNs so nothing broader leaks.
    $bootstrap = $document['Statement'][1];
    expect($bootstrap['Effect'])->toBe('Allow');
    expect($bootstrap['Action'])->toBe([
        'iam:GetUser',
        'iam:GetMFADevice',
        'iam:ListMFADevices',
        'iam:CreateVirtualMFADevice',
        'iam:EnableMFADevice',
        'iam:ResyncMFADevice',
    ]);
    expect($bootstrap['Resource'])->toBe($self);
    expect($bootstrap)->not->toHaveKey('Condition');

    // Account-level console reads — ungated, needed to render the MFA and
    // password screens; neither action supports resource-level scoping.
    $consoleReads = $document['Statement'][2];
    expect($consoleReads['Effect'])->toBe('Allow');
    expect($consoleReads['Action'])->toBe([
        'iam:ListVirtualMFADevices',
        'iam:GetAccountPasswordPolicy',
    ]);
    expect($consoleReads['Resource'])->toBe('*');
    expect($consoleReads)->not->toHaveKey('Condition');

    // Credential self-management — MFA-gated, so a leaked bare key or pre-MFA
    // console session can't cut a fresh key, change the password, or strip the
    // device.
    $credentials = $document['Statement'][3];
    expect($credentials['Effect'])->toBe('Allow');
    expect($credentials['Action'])->toBe([
        'iam:CreateAccessKey',
        'iam:ListAccessKeys',
        'iam:UpdateAccessKey',
        'iam:DeleteAccessKey',
        'iam:ChangePassword',
        'iam:DeactivateMFADevice',
        'iam:DeleteVirtualMFADevice',
    ]);
    expect($credentials['Resource'])->toBe($self);
    expect($credentials['Condition'])->toBe(['Bool' => ['aws:MultiFactorAuthPresent' => 'true']]);
})->with([
    // Env-wide read subsumes per-app read: the env observers grant carries the
    // env role plus a wildcard over every per-app observer role, so app-scoped
    // commands (which mint the narrower role) work for an env observer.
    'env observers -> observer role + all app observer roles' => [fn (): AssumeRoleGroup => new ObserversGroup(), [
        'arn:aws:iam::111111111111:role/yolo-testing-observer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-*-observer-role',
    ]],
    'app observers -> per-app observer role' => [fn (): AssumeRoleGroup => new AppObserversGroup(), 'arn:aws:iam::111111111111:role/yolo-testing-my-app-observer-role'],
    'app deployers -> deployer role' => [fn (): AssumeRoleGroup => new DeployersGroup(), 'arn:aws:iam::111111111111:role/yolo-testing-my-app-deployer'],
    // Admin subsumes every tier: commands mint the least-privileged role for
    // their job, so the admin grant must cover the whole role hierarchy.
    'env admins -> every tier role' => [fn (): AssumeRoleGroup => new AdminsGroup(), [
        'arn:aws:iam::111111111111:role/yolo-testing-admin-role',
        'arn:aws:iam::111111111111:role/yolo-testing-observer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-*-observer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-*-deployer',
    ]],
]);

it('is untaggable — synchroniseTags is a no-op (ownership lives in the name)', function (): void {
    // IAM groups have no tagging API, so there is no tag drift to reconcile; the
    // create-or-sync flow must see no missing tags, not a phantom change.
    expect((new ObserversGroup())->synchroniseTags(apply: false))->toBe([]);
    expect((new AppObserversGroup())->synchroniseTags(apply: true))->toBe([]);
});
