<?php

use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

// The suite itself runs inside GitHub Actions (GITHUB_REPOSITORY set), so clear
// it after each test to keep the inference tests deterministic.
afterEach(function () {
    putenv('GITHUB_REPOSITORY');
    unset($_ENV['GITHUB_REPOSITORY'], $_SERVER['GITHUB_REPOSITORY']);
});

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

it('infers the repository from GITHUB_REPOSITORY when the manifest omits it', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'deployer' => true,
    ]);

    putenv('GITHUB_REPOSITORY=codinglabsau/codinglabs');
    $_ENV['GITHUB_REPOSITORY'] = $_SERVER['GITHUB_REPOSITORY'] = 'codinglabsau/codinglabs';

    $subject = (new DeployerRole())->assumeRolePolicyDocument()['Statement'][0]['Condition']['StringLike'];

    expect($subject['token.actions.githubusercontent.com:sub'])
        ->toBe('repo:codinglabsau/codinglabs:ref:refs/heads/main');
});

it('throws when the repository cannot be inferred and is not set', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'deployer' => true,
    ]);

    // No GITHUB_REPOSITORY and the temp manifest dir is not a git checkout, so
    // there's no origin to parse — the trust policy must fail loudly, never
    // silently build with a missing repo.
    putenv('GITHUB_REPOSITORY');
    unset($_ENV['GITHUB_REPOSITORY'], $_SERVER['GITHUB_REPOSITORY']);

    expect(fn () => (new DeployerRole())->assumeRolePolicyDocument())
        ->toThrow(IntegrityCheckException::class);
});
