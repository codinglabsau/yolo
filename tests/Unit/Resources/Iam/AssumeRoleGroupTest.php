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

it('grants sts:AssumeRole on exactly its tier role plus the self-service credential slice, built purely from the manifest', function (AssumeRoleGroup $group, string $roleArn): void {
    $document = $group->document();
    $self = [
        'arn:aws:iam::111111111111:user/${aws:username}',
        'arn:aws:iam::111111111111:mfa/${aws:username}',
    ];

    expect($document['Version'])->toBe('2012-10-17');
    expect($document['Statement'])->toHaveCount(3);

    $assumeRole = $document['Statement'][0];
    expect($assumeRole['Effect'])->toBe('Allow');
    expect($assumeRole['Action'])->toBe('sts:AssumeRole');
    expect($assumeRole['Resource'])->toBe($roleArn);

    // The MFA bootstrap path — ungated so a new user can enrol their first
    // device, scoped to the member's own user/mfa ARNs so nothing broader leaks.
    $bootstrap = $document['Statement'][1];
    expect($bootstrap['Effect'])->toBe('Allow');
    expect($bootstrap['Action'])->toBe([
        'iam:ListMFADevices',
        'iam:CreateVirtualMFADevice',
        'iam:EnableMFADevice',
        'iam:ResyncMFADevice',
    ]);
    expect($bootstrap['Resource'])->toBe($self);
    expect($bootstrap)->not->toHaveKey('Condition');

    // Credential self-management — MFA-gated, so a leaked bare key can't rotate
    // itself a fresh key or strip the device.
    $credentials = $document['Statement'][2];
    expect($credentials['Effect'])->toBe('Allow');
    expect($credentials['Action'])->toBe([
        'iam:CreateAccessKey',
        'iam:ListAccessKeys',
        'iam:UpdateAccessKey',
        'iam:DeleteAccessKey',
        'iam:DeactivateMFADevice',
        'iam:DeleteVirtualMFADevice',
    ]);
    expect($credentials['Resource'])->toBe($self);
    expect($credentials['Condition'])->toBe(['Bool' => ['aws:MultiFactorAuthPresent' => 'true']]);
})->with([
    'env observers -> observer role' => [fn (): AssumeRoleGroup => new ObserversGroup(), 'arn:aws:iam::111111111111:role/yolo-testing-observer-role'],
    'app observers -> per-app observer role' => [fn (): AssumeRoleGroup => new AppObserversGroup(), 'arn:aws:iam::111111111111:role/yolo-testing-my-app-observer-role'],
    'app deployers -> deployer role' => [fn (): AssumeRoleGroup => new DeployersGroup(), 'arn:aws:iam::111111111111:role/yolo-testing-my-app-deployer'],
    'env admins -> admin role' => [fn (): AssumeRoleGroup => new AdminsGroup(), 'arn:aws:iam::111111111111:role/yolo-testing-admin-role'],
]);

it('is untaggable — synchroniseTags is a no-op (ownership lives in the name)', function (): void {
    // IAM groups have no tagging API, so there is no tag drift to reconcile; the
    // create-or-sync flow must see no missing tags, not a phantom change.
    expect((new ObserversGroup())->synchroniseTags(apply: false))->toBe([]);
    expect((new AppObserversGroup())->synchroniseTags(apply: true))->toBe([]);
});
