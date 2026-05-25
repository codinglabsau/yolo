<?php

use Codinglabs\Yolo\Resources\Iam\DeployerRole;

it('names the deployer role shared per environment', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'deployer' => ['repository' => 'my-org/my-repo'],
    ]);

    expect((new DeployerRole())->name())->toBe('yolo-testing-deployer');
});

it('federates to the GitHub OIDC provider scoped to the repo and branch', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'deployer' => ['repository' => 'my-org/my-repo', 'branch' => 'release'],
    ]);

    expect((new DeployerRole())->assumeRolePolicyDocument())->toBe([
        'Version' => '2012-10-17',
        'Statement' => [
            [
                'Effect' => 'Allow',
                'Principal' => ['Federated' => 'arn:aws:iam::111111111111:oidc-provider/token.actions.githubusercontent.com'],
                'Action' => 'sts:AssumeRoleWithWebIdentity',
                'Condition' => [
                    'StringEquals' => [
                        'token.actions.githubusercontent.com:aud' => 'sts.amazonaws.com',
                    ],
                    'StringLike' => [
                        'token.actions.githubusercontent.com:sub' => 'repo:my-org/my-repo:ref:refs/heads/release',
                    ],
                ],
            ],
        ],
    ]);
});

it('defaults the branch to main when the manifest omits it', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'deployer' => ['repository' => 'my-org/my-repo'],
    ]);

    $subject = (new DeployerRole())->assumeRolePolicyDocument()['Statement'][0]['Condition']['StringLike'];

    expect($subject['token.actions.githubusercontent.com:sub'])
        ->toBe('repo:my-org/my-repo:ref:refs/heads/main');
});
