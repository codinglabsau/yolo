<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\AdminRole;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is an env-scoped role named yolo-{env}-admin-role (shared by the environment)', function (): void {
    expect((new AdminRole())->scope())->toBe(Scope::Env);
    expect((new AdminRole())->name())->toBe('yolo-testing-admin-role');
});

it('trusts the account principal via sts:AssumeRole, so a granted operator can assume it', function (): void {
    $statement = (new AdminRole())->assumeRolePolicyDocument()['Statement'][0];

    expect($statement)->toMatchArray([
        'Effect' => 'Allow',
        'Principal' => ['AWS' => 'arn:aws:iam::111111111111:root'],
        'Action' => 'sts:AssumeRole',
    ]);

    // Same-account assumption — never the GitHub OIDC web-identity trust.
    expect($statement['Action'])->not->toBe('sts:AssumeRoleWithWebIdentity');
});
