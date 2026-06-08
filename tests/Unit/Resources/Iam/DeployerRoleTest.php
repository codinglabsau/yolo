<?php

use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

// The suite runs inside GitHub Actions (GITHUB_REPOSITORY set). Most tests pin
// the repo via an explicit env-level `repository` so inference is deterministic;
// clear GITHUB_REPOSITORY after each test so the env / unresolvable cases are too.
afterEach(function (): void {
    putenv('GITHUB_REPOSITORY');
    unset($_ENV['GITHUB_REPOSITORY'], $_SERVER['GITHUB_REPOSITORY']);
});

function deployerManifest(array $environment = []): void
{
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'repository' => 'my-org/my-repo',
        ...$environment,
    ]);
}

function deployerSubjectFor(array $environment): string
{
    deployerManifest($environment);

    return (new DeployerRole())->assumeRolePolicyDocument()['Statement'][0]['Condition']['StringLike']['token.actions.githubusercontent.com:sub'];
}

it('names the deployer role per app and environment', function (): void {
    deployerManifest();

    expect((new DeployerRole())->name())->toBe('yolo-testing-my-app-deployer');
});

it('federates to the GitHub OIDC provider scoped to the repo and branch', function (): void {
    deployerManifest(['branch' => 'release']);

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

it('defaults to the main branch when no ref is set', function (): void {
    expect(deployerSubjectFor([]))->toBe('repo:my-org/my-repo:ref:refs/heads/main');
});

it('scopes the trust to a branch (staging)', function (): void {
    expect(deployerSubjectFor(['branch' => 'develop']))->toBe('repo:my-org/my-repo:ref:refs/heads/develop');
});

it('scopes the trust to a tag pattern (production)', function (): void {
    expect(deployerSubjectFor(['tag' => 'v*']))->toBe('repo:my-org/my-repo:ref:refs/tags/v*');
});

it('treats tag: true as any tag', function (): void {
    expect(deployerSubjectFor(['tag' => true]))->toBe('repo:my-org/my-repo:ref:refs/tags/*');
});

it('throws when both a branch and a tag are set', function (): void {
    expect(fn (): string => deployerSubjectFor(['branch' => 'main', 'tag' => 'v*']))
        ->toThrow(IntegrityCheckException::class);
});

it('infers the repository from GITHUB_REPOSITORY when the manifest omits it', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    putenv('GITHUB_REPOSITORY=codinglabsau/codinglabs');
    $_ENV['GITHUB_REPOSITORY'] = $_SERVER['GITHUB_REPOSITORY'] = 'codinglabsau/codinglabs';

    $subject = (new DeployerRole())->assumeRolePolicyDocument()['Statement'][0]['Condition']['StringLike'];

    expect($subject['token.actions.githubusercontent.com:sub'])
        ->toBe('repo:codinglabsau/codinglabs:ref:refs/heads/main');
});

it('throws when the repository cannot be resolved', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    // No manifest repository, no GITHUB_REPOSITORY, and the temp manifest dir is
    // not a git checkout — the trust must fail loudly, never build with a blank repo.
    putenv('GITHUB_REPOSITORY');
    unset($_ENV['GITHUB_REPOSITORY'], $_SERVER['GITHUB_REPOSITORY']);

    expect(fn (): array => (new DeployerRole())->assumeRolePolicyDocument())
        ->toThrow(IntegrityCheckException::class);
});
