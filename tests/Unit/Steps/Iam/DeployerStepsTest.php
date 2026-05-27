<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;
use Codinglabs\Yolo\Steps\Sync\App\SyncDeployerRoleStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncDeployerPolicyStep;
use Codinglabs\Yolo\Steps\Sync\Account\SyncGithubOidcProviderStep;
use Codinglabs\Yolo\Steps\Sync\App\AttachDeployerRolePoliciesStep;

// The suite runs in GitHub Actions (GITHUB_REPOSITORY set). Clear it after each
// test; provisioning tests pin the repo via an explicit env-level `repository`.
afterEach(function () {
    putenv('GITHUB_REPOSITORY');
    unset($_ENV['GITHUB_REPOSITORY'], $_SERVER['GITHUB_REPOSITORY']);
});

function existingDeployerPolicy(): array
{
    return [
        'PolicyName' => 'yolo-testing-my-app-deployer-policy',
        'Arn' => 'arn:aws:iam::111111111111:policy/yolo-testing-my-app-deployer-policy',
        'DefaultVersionId' => 'v1',
    ];
}

function existingDeployerRole(): array
{
    return [
        'RoleName' => 'yolo-testing-my-app-deployer',
        'Arn' => 'arn:aws:iam::111111111111:role/yolo-testing-my-app-deployer',
    ];
}

/** A manifest with a resolvable GitHub repository — the deployer provisions. */
function manifestWithDeployer(array $environment = []): void
{
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'repository' => 'my-org/my-repo',
        ...$environment,
    ]);
}

/**
 * No resolvable repository — GITHUB_REPOSITORY cleared, no manifest override, and
 * the temp dir isn't a git checkout — so the deployer steps skip.
 */
function manifestWithoutRepository(): void
{
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);

    putenv('GITHUB_REPOSITORY');
    unset($_ENV['GITHUB_REPOSITORY'], $_SERVER['GITHUB_REPOSITORY']);
}

it('skips every deployer step when no GitHub repository is resolvable', function () {
    manifestWithoutRepository();

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
    expect($create['args']['PolicyName'])->toBe('yolo-testing-my-app-deployer-policy');
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
    expect($create['args']['RoleName'])->toBe('yolo-testing-my-app-deployer');
    expect($create['args']['AssumeRolePolicyDocument'])->toContain('sts:AssumeRoleWithWebIdentity');
});

it('would-sync the policy attachment on a dry-run without attaching', function () {
    manifestWithDeployer();

    $captured = [];
    // No AttachedPolicies returned → the deployer policy is not attached yet, so a
    // dry-run reports the pending attachment but never calls AttachRolePolicy.
    bindRoutedIamClient([], $captured);

    expect((new AttachDeployerRolePoliciesStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect(array_column($captured, 'name'))->not->toContain('AttachRolePolicy');
});

it('reports the policy attachment as synced when it is already attached', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListAttachedRolePolicies' => new Result(['AttachedPolicies' => [
            ['PolicyName' => 'yolo-testing-my-app-deployer-policy', 'PolicyArn' => 'arn:aws:iam::111111111111:policy/yolo-testing-my-app-deployer-policy'],
        ]]),
    ], $captured);

    expect((new AttachDeployerRolePoliciesStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain('AttachRolePolicy');
});

it('attaches the deployer policy to the deployer role', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [existingDeployerPolicy()]]),
    ], $captured);

    expect((new AttachDeployerRolePoliciesStep())([]))->toBe(StepResult::SYNCED);

    $attach = collect($captured)->firstWhere('name', 'AttachRolePolicy');
    expect($attach['args']['RoleName'])->toBe('yolo-testing-my-app-deployer');
    expect($attach['args']['PolicyArn'])->toBe('arn:aws:iam::111111111111:policy/yolo-testing-my-app-deployer-policy');
});

it('does not create a new policy version when the deployer document is unchanged', function () {
    manifestWithDeployer();

    $document = json_encode((new DeployerPolicy())->document());

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [existingDeployerPolicy()]]),
        'GetPolicyVersion' => new Result(['PolicyVersion' => ['Document' => rawurlencode($document)]]),
    ], $captured);

    expect((new SyncDeployerPolicyStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain('CreatePolicyVersion');
});

it('reconciles the deployer policy by creating a new default version when it drifts', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [existingDeployerPolicy()]]),
        // A stale document that no longer matches the rendered one.
        'GetPolicyVersion' => new Result(['PolicyVersion' => ['Document' => rawurlencode('{"Version":"2012-10-17","Statement":[]}')]]),
    ], $captured);

    expect((new SyncDeployerPolicyStep())([]))->toBe(StepResult::SYNCED);

    $version = collect($captured)->firstWhere('name', 'CreatePolicyVersion');
    expect($version)->not->toBeNull();
    expect($version['args']['SetAsDefault'])->toBeTrue();
});

it('reconciles the deployer role trust policy when the env ref changes', function () {
    manifestWithDeployer(['branch' => 'release']);

    $captured = [];
    bindRoutedIamClient([
        'ListRoles' => new Result(['Roles' => [existingDeployerRole()]]),
    ], $captured);

    expect((new SyncDeployerRoleStep())([]))->toBe(StepResult::SYNCED);

    $update = collect($captured)->firstWhere('name', 'UpdateAssumeRolePolicy');
    expect($update)->not->toBeNull();

    $sub = json_decode($update['args']['PolicyDocument'], true)['Statement'][0]['Condition']['StringLike']['token.actions.githubusercontent.com:sub'];
    expect($sub)->toBe('repo:my-org/my-repo:ref:refs/heads/release');
});

it('never mutates IAM on a dry-run against existing deployer resources', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [existingDeployerPolicy()]]),
        'ListRoles' => new Result(['Roles' => [existingDeployerRole()]]),
    ], $captured);

    expect((new SyncDeployerPolicyStep())(['dry-run' => true]))->toBe(StepResult::SYNCED);
    expect((new SyncDeployerRoleStep())(['dry-run' => true]))->toBe(StepResult::SYNCED);

    expect(array_column($captured, 'name'))
        ->not->toContain('CreatePolicyVersion')
        ->not->toContain('UpdateAssumeRolePolicy')
        ->not->toContain('CreatePolicy')
        ->not->toContain('CreateRole');
});
