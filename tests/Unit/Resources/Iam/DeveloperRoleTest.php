<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\DeveloperRole;
use Codinglabs\Yolo\Resources\Iam\AppDeveloperRole;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('names the env and per-app developer roles in their tier scope', function (): void {
    expect((new DeveloperRole())->scope())->toBe(Scope::Env);
    expect((new DeveloperRole())->name())->toBe('yolo-testing-developer-role');

    expect((new AppDeveloperRole())->scope())->toBe(Scope::App);
    expect((new AppDeveloperRole())->name())->toBe('yolo-testing-my-app-developer-role');
});

it('trusts the MFA\'d account principal — same-account assumption, never OIDC', function (DeveloperRole $role): void {
    $statement = $role->assumeRolePolicyDocument()['Statement'][0];

    expect($statement)->toMatchArray([
        'Effect' => 'Allow',
        'Principal' => ['AWS' => 'arn:aws:iam::111111111111:root'],
        'Action' => 'sts:AssumeRole',
    ]);
    expect($statement['Condition'])->toBe(['Bool' => ['aws:MultiFactorAuthPresent' => 'true']]);
})->with([
    'env' => [fn (): DeveloperRole => new DeveloperRole()],
    'app' => [fn (): DeveloperRole => new AppDeveloperRole()],
]);
