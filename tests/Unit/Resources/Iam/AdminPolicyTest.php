<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\AdminPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

/** Every action across every statement, flattened. */
function adminActions(): array
{
    return collect((new AdminPolicy())->document()['Statement'])
        ->flatMap(fn (array $statement): array => (array) $statement['Action'])
        ->all();
}

it('is an env-scoped policy named yolo-{env}-admin', function (): void {
    expect((new AdminPolicy())->scope())->toBe(Scope::Env);
    expect((new AdminPolicy())->name())->toBe('yolo-testing-admin');
});

it('builds a pure document from the manifest (no live AWS calls)', function (): void {
    $document = (new AdminPolicy())->document();

    expect($document['Version'])->toBe('2012-10-17')
        ->and($document['Statement'])->not->toBeEmpty();
});

it('grants the write surface for the services YOLO provisions', function (): void {
    expect(adminActions())->toContain(
        'ecs:Create*',
        'ec2:Create*',
        'elasticloadbalancing:Create*',
        'application-autoscaling:RegisterScalableTarget',
        'application-autoscaling:PutScalingPolicy',
        'cloudfront:Create*',
        'route53:ChangeResourceRecordSets',
        'wafv2:Create*',
    );
});

it('never grants a blanket wildcard or blanket IAM', function (): void {
    $actions = adminActions();

    expect($actions)->not->toContain('*')
        ->and($actions)->not->toContain('iam:*')
        ->and($actions)->not->toContain('iam:CreateUser')
        ->and($actions)->not->toContain('iam:CreateAccessKey')
        ->and($actions)->not->toContain('sts:AssumeRole');
});

it('scopes every IAM role/policy/oidc action to yolo-* resources', function (): void {
    $iamStatements = collect((new AdminPolicy())->document()['Statement'])
        ->filter(fn (array $statement): bool => collect((array) $statement['Action'])
            ->contains(fn (string $action): bool => str_starts_with($action, 'iam:')));

    expect($iamStatements)->not->toBeEmpty();

    // No IAM statement may reach beyond yolo-* (roles/policies/groups/oidc) or
    // the service-linked-role + PassRole carve-outs — except the `yolo permissions`
    // picker reads, which are unscopeable collection ops (list users / a user's
    // groups) granted read-only on "*".
    $iamStatements->each(function (array $statement): void {
        $isPickerRead = collect((array) $statement['Action'])
            ->every(fn (string $action): bool => in_array($action, ['iam:ListUsers', 'iam:ListGroupsForUser'], true));

        if ($isPickerRead) {
            return;
        }

        foreach ((array) $statement['Resource'] as $resource) {
            expect($resource)->toMatch('#(yolo-\*|:oidc-provider/\*|aws-service-role/\*|role/yolo-)#');
        }
    });
});

it('manages YOLO grant groups + membership, fenced to yolo-* groups', function (): void {
    $groupStatement = collect((new AdminPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => in_array('iam:AddUserToGroup', (array) $statement['Action'], true));

    expect($groupStatement)->not->toBeNull();

    // The membership lever + group lifecycle are scoped to yolo-* groups — never
    // an arbitrary IAM group.
    expect($groupStatement['Resource'])->toBe('arn:aws:iam::111111111111:group/yolo-*');
    expect($groupStatement['Action'])->toContain(
        'iam:CreateGroup',
        'iam:PutGroupPolicy',
        'iam:AddUserToGroup',
        'iam:RemoveUserFromGroup',
    );

    // No user lifecycle — YOLO manages access (group membership), never identities.
    expect(adminActions())
        ->not->toContain('iam:CreateUser')
        ->not->toContain('iam:DeleteUser')
        ->not->toContain('iam:CreateAccessKey');
});

it('fences AttachRolePolicy so only a yolo-* customer-managed policy can be attached (no escalation)', function (): void {
    $attach = collect((new AdminPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => in_array('iam:AttachRolePolicy', (array) $statement['Action'], true));

    expect($attach)->not->toBeNull();

    // The chokepoint: AttachRolePolicy is conditioned on the policy ARN being a
    // yolo-* customer-managed policy. AWS-managed AdministratorAccess (account
    // "aws") can never match, so the tier can't grant itself broad access.
    expect($attach['Condition']['ArnLike']['iam:PolicyARN'])
        ->toBe('arn:aws:iam::111111111111:policy/yolo-*');

    expect($attach['Resource'])->toBe('arn:aws:iam::111111111111:role/yolo-*');
});

it('scopes PassRole to yolo-* roles for the ECS tasks service only', function (): void {
    $passRole = collect((new AdminPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => in_array('iam:PassRole', (array) $statement['Action'], true));

    expect($passRole)->not->toBeNull();
    expect($passRole['Resource'])->toBe('arn:aws:iam::111111111111:role/yolo-*');
    expect($passRole['Condition']['StringEquals']['iam:PassedToService'])->toBe('ecs-tasks.amazonaws.com');
});

it('limits service-linked-role creation to the three services that need it', function (): void {
    $slr = collect((new AdminPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => in_array('iam:CreateServiceLinkedRole', (array) $statement['Action'], true));

    expect($slr)->not->toBeNull();
    expect($slr['Condition']['StringEquals']['iam:AWSServiceName'])->toBe([
        'ecs.amazonaws.com',
        'application-autoscaling.amazonaws.com',
        'elasticache.amazonaws.com',
    ]);
});

it('grants S3 object write to the env manifest + app claim keys, never the env-shared .env', function (): void {
    $objectWrite = collect((new AdminPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => (array) $statement['Action'] === ['s3:PutObject']);

    expect($objectWrite)->not->toBeNull();

    $resources = implode(' ', (array) $objectWrite['Resource']);

    // Both objects sync writes — the env manifest (SeedEnvManifestStep) and the
    // per-app claim prefix (PublishAppManifestStep) — but never the env-shared `.env`.
    expect($resources)
        ->toContain('yolo-environment-testing.yml')
        ->toContain('/apps/*')
        ->not->toContain('.env');
});
