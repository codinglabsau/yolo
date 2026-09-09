<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\AdminsGroup;
use Codinglabs\Yolo\Resources\Iam\ObserversGroup;
use Codinglabs\Yolo\Resources\Iam\AssumeRoleGroup;
use Codinglabs\Yolo\Resources\Iam\DevelopersGroup;
use Codinglabs\Yolo\Resources\Iam\AppObserversGroup;
use Codinglabs\Yolo\Resources\Iam\AppDevelopersGroup;

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
    'env developers' => [fn (): AssumeRoleGroup => new DevelopersGroup(), Scope::Env, 'yolo-testing-developers'],
    'app developers' => [fn (): AssumeRoleGroup => new AppDevelopersGroup(), Scope::App, 'yolo-testing-my-app-developers'],
    'env admins' => [fn (): AssumeRoleGroup => new AdminsGroup(), Scope::Env, 'yolo-testing-admins'],
]);

it('grants sts:AssumeRole on exactly its tier role and nothing else, built purely from the manifest', function (AssumeRoleGroup $group, string|array $roleArn): void {
    // The self-service credential slice lives exclusively in UsersGroup now
    // (see UsersGroupTest) — a tier group's whole grant is this one statement.
    $document = $group->document();

    expect($document['Version'])->toBe('2012-10-17');
    expect($document['Statement'])->toHaveCount(1);

    $assumeRole = $document['Statement'][0];
    expect($assumeRole['Effect'])->toBe('Allow');
    expect($assumeRole['Action'])->toBe('sts:AssumeRole');
    expect($assumeRole['Resource'])->toBe($roleArn);
    expect($assumeRole)->not->toHaveKey('Condition');
})->with([
    // Env-wide read subsumes per-app read: the env observers grant carries the
    // env role plus a wildcard over every per-app observer role, so app-scoped
    // commands (which mint the narrower role) work for an env observer.
    'env observers -> observer role + all app observer roles' => [fn (): AssumeRoleGroup => new ObserversGroup(), [
        'arn:aws:iam::111111111111:role/yolo-testing-observer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-*-observer-role',
    ]],
    'app observers -> per-app observer role' => [fn (): AssumeRoleGroup => new AppObserversGroup(), 'arn:aws:iam::111111111111:role/yolo-testing-my-app-observer-role'],
    // Developer subsumes observer (read commands mint observer roles) but never
    // the deployer: humans deploy through CI, admins from a laptop.
    'env developers -> developer + observer roles, never the deployer' => [fn (): AssumeRoleGroup => new DevelopersGroup(), [
        'arn:aws:iam::111111111111:role/yolo-testing-developer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-observer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-*-observer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-*-developer-role',
    ]],
    'app developers -> per-app developer + observer roles' => [fn (): AssumeRoleGroup => new AppDevelopersGroup(), [
        'arn:aws:iam::111111111111:role/yolo-testing-my-app-developer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-my-app-observer-role',
    ]],
    // Admin subsumes every tier: commands mint the least-privileged role for
    // their job, so the admin grant must cover the whole role hierarchy — the
    // per-app deployer roles included, which is what makes a laptop deploy an
    // admin act (there is no human deployer grant).
    'env admins -> every tier role' => [fn (): AssumeRoleGroup => new AdminsGroup(), [
        'arn:aws:iam::111111111111:role/yolo-testing-admin-role',
        'arn:aws:iam::111111111111:role/yolo-testing-observer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-developer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-*-observer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-*-developer-role',
        'arn:aws:iam::111111111111:role/yolo-testing-*-deployer',
    ]],
]);

it('is untaggable — synchroniseTags is a no-op (ownership lives in the name)', function (): void {
    // IAM groups have no tagging API, so there is no tag drift to reconcile; the
    // create-or-sync flow must see no missing tags, not a phantom change.
    expect((new ObserversGroup())->synchroniseTags(apply: false))->toBe([]);
    expect((new AppObserversGroup())->synchroniseTags(apply: true))->toBe([]);
});
