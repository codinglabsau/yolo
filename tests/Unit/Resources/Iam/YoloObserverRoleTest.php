<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\YoloObserverRole;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is an env-scoped role named yolo-{env}-observer-role (shared by the environment)', function (): void {
    expect((new YoloObserverRole())->scope())->toBe(Scope::Env);
    expect((new YoloObserverRole())->name())->toBe('yolo-testing-observer-role');
});

it('trusts the account principal, so an identity granted sts:AssumeRole can assume it', function (): void {
    $statement = (new YoloObserverRole())->assumeRolePolicyDocument()['Statement'][0];

    expect($statement)->toMatchArray([
        'Effect' => 'Allow',
        'Principal' => ['AWS' => 'arn:aws:iam::111111111111:root'],
        'Action' => 'sts:AssumeRole',
    ]);

    // It's same-account role assumption — never the GitHub OIDC web-identity trust
    // the deployer role uses.
    expect($statement['Action'])->not->toBe('sts:AssumeRoleWithWebIdentity');
});
