<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Iam\SyncDeployerRoleStep;
use Codinglabs\Yolo\Steps\Iam\SyncDeployerPolicyStep;
use Codinglabs\Yolo\Steps\Iam\SyncGithubOidcProviderStep;
use Codinglabs\Yolo\Steps\Iam\AttachDeployerRolePoliciesStep;

function manifestWithDeployer(): void
{
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'deployer' => ['repository' => 'my-org/my-repo'],
    ]);
}

function manifestWithoutDeployer(): void
{
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
}

it('skips every deployer step when the manifest has no deployer block', function () {
    manifestWithoutDeployer();

    $captured = [];
    bindRoutedIamClient([], $captured);

    expect((new SyncGithubOidcProviderStep())([]))->toBe(StepResult::SKIPPED);
    expect((new SyncDeployerPolicyStep())([]))->toBe(StepResult::SKIPPED);
    expect((new SyncDeployerRoleStep())([]))->toBe(StepResult::SKIPPED);
    expect((new AttachDeployerRolePoliciesStep())([]))->toBe(StepResult::SKIPPED);

    expect($captured)->toBeEmpty();
});

it('creates the OIDC provider when absent', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListOpenIDConnectProviders' => new Result(['OpenIDConnectProviderList' => []]),
    ], $captured);

    expect((new SyncGithubOidcProviderStep())([]))->toBe(StepResult::CREATED);
    expect(array_column($captured, 'name'))->toContain('CreateOpenIDConnectProvider');
});

it('reports the OIDC provider as synced when it already exists', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListOpenIDConnectProviders' => new Result([
            'OpenIDConnectProviderList' => [
                ['Arn' => 'arn:aws:iam::111111111111:oidc-provider/token.actions.githubusercontent.com'],
            ],
        ]),
    ], $captured);

    expect((new SyncGithubOidcProviderStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain('CreateOpenIDConnectProvider');
});

it('would-create the OIDC provider on a dry-run without calling create', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListOpenIDConnectProviders' => new Result(['OpenIDConnectProviderList' => []]),
    ], $captured);

    expect((new SyncGithubOidcProviderStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(array_column($captured, 'name'))->not->toContain('CreateOpenIDConnectProvider');
});

it('creates the deployer policy when absent', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => []]),
    ], $captured);

    expect((new SyncDeployerPolicyStep())([]))->toBe(StepResult::CREATED);

    $create = collect($captured)->firstWhere('name', 'CreatePolicy');
    expect($create)->not->toBeNull();
    expect($create['args']['PolicyName'])->toBe('yolo-testing-deployer-policy');
});

it('creates the deployer role with the OIDC trust policy when absent', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListRoles' => new Result(['Roles' => []]),
    ], $captured);

    expect((new SyncDeployerRoleStep())([]))->toBe(StepResult::CREATED);

    $create = collect($captured)->firstWhere('name', 'CreateRole');
    expect($create)->not->toBeNull();
    expect($create['args']['RoleName'])->toBe('yolo-testing-deployer');
    expect($create['args']['AssumeRolePolicyDocument'])->toContain('sts:AssumeRoleWithWebIdentity');
});

it('would-sync the policy attachment on a dry-run', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([], $captured);

    expect((new AttachDeployerRolePoliciesStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($captured)->toBeEmpty();
});

it('attaches the deployer policy to the deployer role', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [
            [
                'PolicyName' => 'yolo-testing-deployer-policy',
                'Arn' => 'arn:aws:iam::111111111111:policy/yolo-testing-deployer-policy',
            ],
        ]]),
    ], $captured);

    expect((new AttachDeployerRolePoliciesStep())([]))->toBe(StepResult::SYNCED);

    $attach = collect($captured)->firstWhere('name', 'AttachRolePolicy');
    expect($attach['args']['RoleName'])->toBe('yolo-testing-deployer');
    expect($attach['args']['PolicyArn'])->toBe('arn:aws:iam::111111111111:policy/yolo-testing-deployer-policy');
});
