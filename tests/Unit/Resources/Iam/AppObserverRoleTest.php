<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\AppObserverRole;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is an app-scoped role named yolo-{env}-{app}-observer-role, so a grant can name one app', function (): void {
    expect((new AppObserverRole())->scope())->toBe(Scope::App);
    expect((new AppObserverRole())->name())->toBe('yolo-testing-my-app-observer-role');
});

it('trusts the account principal for same-account assumption — never OIDC', function (): void {
    $statement = (new AppObserverRole())->assumeRolePolicyDocument()['Statement'][0];

    expect($statement)->toMatchArray([
        'Effect' => 'Allow',
        'Principal' => ['AWS' => 'arn:aws:iam::111111111111:root'],
        'Action' => 'sts:AssumeRole',
    ]);

    // Reads are for humans/agents, not CI — no GitHub web-identity trust.
    expect($statement['Action'])->not->toBe('sts:AssumeRoleWithWebIdentity');
});
