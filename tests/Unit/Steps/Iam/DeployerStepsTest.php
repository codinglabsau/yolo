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

function existingDeployerRole(?string $trustSubject = null): array
{
    return [
        'RoleName' => 'yolo-testing-my-app-deployer',
        'Arn' => 'arn:aws:iam::111111111111:role/yolo-testing-my-app-deployer',
        // IAM returns the live trust URL-encoded on the role record. Default to the
        // main-branch trust so a default (main) manifest reads as in-sync and a
        // branch/tag manifest reads as drifted.
        'AssumeRolePolicyDocument' => deployerTrustDocument($trustSubject ?? 'repo:my-org/my-repo:ref:refs/heads/main'),
    ];
}

/** A URL-encoded OIDC trust document scoped to the given `sub` claim. */
function deployerTrustDocument(string $subject): string
{
    return rawurlencode(json_encode([
        'Version' => '2012-10-17',
        'Statement' => [
            [
                'Effect' => 'Allow',
                'Principal' => ['Federated' => 'arn:aws:iam::111111111111:oidc-provider/token.actions.githubusercontent.com'],
                'Action' => 'sts:AssumeRoleWithWebIdentity',
                'Condition' => [
                    'StringEquals' => ['token.actions.githubusercontent.com:aud' => 'sts.amazonaws.com'],
                    'StringLike' => ['token.actions.githubusercontent.com:sub' => $subject],
                ],
            ],
        ],
    ]));
}

/** A manifest with a resolvable GitHub repository — the deployer provisions. */
function manifestWithDeployer(array $environment = []): void
{
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
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
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
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

it('records deployer trust drift on the plan pass so the step survives the prune', function () {
    // Regression (the convict `tag: true` OIDC failure): the trust rewrite used to
    // happen only under `! dry-run` and recorded no Change, so the plan pass saw a
    // clean step, the only-pending-steps filter pruned it before apply, and a
    // role created on `main` could never be self-healed to `refs/tags/*`. The drift
    // must be recorded on the plan (dry-run) pass — without writing — so the step
    // survives the prune.
    manifestWithDeployer(['tag' => true]);

    $captured = [];
    bindRoutedIamClient([
        // Live trust still pinned to the old main-branch ref.
        'ListRoles' => new Result(['Roles' => [existingDeployerRole('repo:my-org/my-repo:ref:refs/heads/main')]]),
    ], $captured);

    $step = new SyncDeployerRoleStep();
    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);

    $trustChange = collect($step->changes())->first(fn ($change) => str_contains($change->attribute, 'trust'));
    expect($trustChange)->not->toBeNull();
    // The diff must read live → desired, not desired → desired (guards the trait
    // method being shadowed by DeployerRole's own subjectClaim()).
    expect($trustChange->from)->toBe('repo:my-org/my-repo:ref:refs/heads/main');
    expect($trustChange->to)->toBe('repo:my-org/my-repo:ref:refs/tags/*');

    // Plan pass computes only — it must never rewrite the trust.
    expect(array_column($captured, 'name'))->not->toContain('UpdateAssumeRolePolicy');
});

it('records no trust change when the deployer trust already matches', function () {
    // The other half of the regression: an in-sync trust must produce no pending
    // entry, otherwise every sync re-stamps it and the confirm gate never clears.
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        // Live trust already on the default main ref — matches the rendered desired.
        'ListRoles' => new Result(['Roles' => [existingDeployerRole()]]),
        'ListRoleTags' => new Result(['Tags' => [
            ['Key' => 'Name', 'Value' => 'yolo-testing-my-app-deployer'],
            ['Key' => 'yolo:scope', 'Value' => 'app'],
            ['Key' => 'yolo:app', 'Value' => 'my-app'],
            ['Key' => 'yolo:environment', 'Value' => 'testing'],
        ]]),
    ], $captured);

    $step = new SyncDeployerRoleStep();
    expect($step([]))->toBe(StepResult::SYNCED);

    $trustChanges = collect($step->changes())->filter(fn ($change) => str_contains($change->attribute, 'trust'));
    expect($trustChanges)->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('UpdateAssumeRolePolicy');
});

it('never mutates IAM on a dry-run against existing deployer resources', function () {
    manifestWithDeployer();

    // Pre-stamp the expected YOLO tags on the mocked existing policy + role so
    // the dry-run sees zero tag drift and reports clean SYNCED — otherwise the
    // missing-tag delta surfaces as WOULD_SYNC (the new plan-time tag-drift
    // signal, deliberate behaviour from the SynchronisesResource fix).
    $policyTags = [
        ['Key' => 'Name', 'Value' => 'yolo-testing-my-app-deployer-policy'],
        ['Key' => 'yolo:scope', 'Value' => 'app'],
        ['Key' => 'yolo:app', 'Value' => 'my-app'],
        ['Key' => 'yolo:environment', 'Value' => 'testing'],
    ];
    $roleTags = [
        ['Key' => 'Name', 'Value' => 'yolo-testing-my-app-deployer'],
        ['Key' => 'yolo:scope', 'Value' => 'app'],
        ['Key' => 'yolo:app', 'Value' => 'my-app'],
        ['Key' => 'yolo:environment', 'Value' => 'testing'],
    ];

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [existingDeployerPolicy()]]),
        'ListRoles' => new Result(['Roles' => [existingDeployerRole()]]),
        'ListPolicyTags' => new Result(['Tags' => $policyTags]),
        'ListRoleTags' => new Result(['Tags' => $roleTags]),
        // The live document matches the rendered one, so the document reconciler
        // sees no drift and the dry-run stays clean SYNCED.
        'GetPolicyVersion' => new Result(['PolicyVersion' => [
            'Document' => rawurlencode(json_encode((new DeployerPolicy())->document())),
        ]]),
    ], $captured);

    expect((new SyncDeployerPolicyStep())(['dry-run' => true]))->toBe(StepResult::SYNCED);
    expect((new SyncDeployerRoleStep())(['dry-run' => true]))->toBe(StepResult::SYNCED);

    expect(array_column($captured, 'name'))
        ->not->toContain('CreatePolicyVersion')
        ->not->toContain('UpdateAssumeRolePolicy')
        ->not->toContain('CreatePolicy')
        ->not->toContain('CreateRole')
        ->not->toContain('TagPolicy')
        ->not->toContain('TagRole');
});

it('flags deployer document drift during the plan pass without creating a version', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [existingDeployerPolicy()]]),
        // A stale document — drift the plan must surface so the apply pass isn't
        // dropped by SyncSteppedCommand's only-pending-steps filter.
        'GetPolicyVersion' => new Result(['PolicyVersion' => [
            'Document' => rawurlencode('{"Version":"2012-10-17","Statement":[]}'),
        ]]),
    ], $captured);

    // dry-run = plan pass: drift is detected and reported as WOULD_SYNC, but no
    // version is written.
    expect((new SyncDeployerPolicyStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect(array_column($captured, 'name'))->not->toContain('CreatePolicyVersion');
});

it('prunes the oldest non-default version when the policy is at the 5-version limit before re-versioning', function () {
    manifestWithDeployer();

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [existingDeployerPolicy()]]),
        'GetPolicyVersion' => new Result(['PolicyVersion' => [
            'Document' => rawurlencode('{"Version":"2012-10-17","Statement":[]}'),
        ]]),
        // Five versions already exist — createPolicyVersion would LimitExceed
        // without pruning. v1 is the oldest non-default; v3 is default (untouched).
        'ListPolicyVersions' => new Result(['Versions' => [
            ['VersionId' => 'v3', 'IsDefaultVersion' => true, 'CreateDate' => '2026-05-25T11:05:25+00:00'],
            ['VersionId' => 'v1', 'IsDefaultVersion' => false, 'CreateDate' => '2026-05-25T10:21:17+00:00'],
            ['VersionId' => 'v2', 'IsDefaultVersion' => false, 'CreateDate' => '2026-05-25T10:41:53+00:00'],
            ['VersionId' => 'v4', 'IsDefaultVersion' => false, 'CreateDate' => '2026-05-25T11:30:00+00:00'],
            ['VersionId' => 'v5', 'IsDefaultVersion' => false, 'CreateDate' => '2026-05-25T11:45:00+00:00'],
        ]]),
    ], $captured);

    expect((new SyncDeployerPolicyStep())([]))->toBe(StepResult::SYNCED);

    $delete = collect($captured)->firstWhere('name', 'DeletePolicyVersion');
    expect($delete)->not->toBeNull();
    expect($delete['args']['VersionId'])->toBe('v1'); // oldest non-default

    // The prune happens before the new version is created.
    $names = array_column($captured, 'name');
    expect(array_search('DeletePolicyVersion', $names, true))
        ->toBeLessThan(array_search('CreatePolicyVersion', $names, true));
});
