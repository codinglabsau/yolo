<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\ObserverRole;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is an env-scoped role named yolo-{env}-observer-role (shared by the environment)', function (): void {
    expect((new ObserverRole())->scope())->toBe(Scope::Env);
    expect((new ObserverRole())->name())->toBe('yolo-testing-observer-role');
});

it('trusts the account principal, so an identity granted sts:AssumeRole can assume it', function (): void {
    $statement = (new ObserverRole())->assumeRolePolicyDocument()['Statement'][0];

    expect($statement)->toMatchArray([
        'Effect' => 'Allow',
        'Principal' => ['AWS' => 'arn:aws:iam::111111111111:root'],
        'Action' => 'sts:AssumeRole',
    ]);

    // It's same-account role assumption — never the GitHub OIDC web-identity trust
    // the deployer role uses.
    expect($statement['Action'])->not->toBe('sts:AssumeRoleWithWebIdentity');
});

it('requires MFA to assume — every tier does, even read-only', function (): void {
    $statement = (new ObserverRole())->assumeRolePolicyDocument()['Statement'][0];

    expect($statement['Condition'])->toBe(['Bool' => ['aws:MultiFactorAuthPresent' => 'true']]);
});
