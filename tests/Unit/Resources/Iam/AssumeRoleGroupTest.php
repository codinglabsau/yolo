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

it('grants nothing but sts:AssumeRole on exactly its tier role, built purely from the manifest', function (AssumeRoleGroup $group, string $roleArn): void {
    $document = $group->document();

    expect($document['Version'])->toBe('2012-10-17');
    expect($document['Statement'])->toHaveCount(1);

    $statement = $document['Statement'][0];
    expect($statement['Effect'])->toBe('Allow');
    expect($statement['Action'])->toBe('sts:AssumeRole');
    expect($statement['Resource'])->toBe($roleArn);
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
