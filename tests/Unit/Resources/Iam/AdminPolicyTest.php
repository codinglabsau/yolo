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

it('grants the ECR login + layer-upload chain sync needs to push the Typesense image', function (): void {
    // BuildTypesenseImageStep runs under the admin tier (it's a sync:environment
    // step), so admin must hold the same push chain the deployer uses — the
    // management wildcards (Create*/Delete*/Put*/…) don't cover these verbs, and
    // GetAuthorizationToken is the login call that errored when they were missing.
    // BatchGetImage is a read, but docker HEADs the manifest by digest as part of
    // the push handshake (the observer's Describe*/List* don't cover it), so a push
    // 403s on the manifest check without it.
    expect(adminActions())->toContain(
        'ecr:GetAuthorizationToken',
        'ecr:BatchCheckLayerAvailability',
        'ecr:BatchGetImage',
        'ecr:InitiateLayerUpload',
        'ecr:UploadLayerPart',
        'ecr:CompleteLayerUpload',
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

it('allows DetachRolePolicy unconditioned (detach only removes access, never escalates)', function (): void {
    $detach = collect((new AdminPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => in_array('iam:DetachRolePolicy', (array) $statement['Action'], true));

    expect($detach)->not->toBeNull();

    // Detach is split out from Attach: still fenced to yolo-* roles, but with NO
    // iam:PolicyARN condition. Removing a policy can only reduce a role's access,
    // so the tier must be able to detach an AWS-managed policy an older YOLO once
    // attached (e.g. AmazonRekognitionReadOnlyAccess) when a service grant moves
    // into the app's own yolo-* policy — the Attach condition would block that.
    expect($detach['Resource'])->toBe('arn:aws:iam::111111111111:role/yolo-*');
    expect($detach)->not->toHaveKey('Condition');
    expect($detach['Action'])->not->toContain('iam:AttachRolePolicy');
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

it('grants get+put on YOLO env-tier secret channels: the env-shared .env and each app env-side .env', function (): void {
    // The minted-secret channel: get+put on the env-shared `.env` (the cluster
    // admin key) and the whole env/* prefix (each app's env-side `.env`).
    $secretChannel = collect((new AdminPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => (array) $statement['Action'] === ['s3:GetObject', 's3:PutObject']);

    expect($secretChannel)->not->toBeNull();
    expect($secretChannel['Resource'])->toBe([
        'arn:aws:s3:::yolo-111111111111-testing-config/.env.environment.testing',
        'arn:aws:s3:::yolo-111111111111-testing-config/env/*',
    ]);

    // The only S3 GetObject admin holds is on this env-tier channel — never the
    // per-app developer `.env` (a per-app config bucket the admin tier is fenced
    // from). No statement reaches a per-app `-config` bucket here.
    $getResources = collect((new AdminPolicy())->document()['Statement'])
        ->filter(fn (array $statement): bool => in_array('s3:GetObject', (array) $statement['Action'], true))
        ->flatMap(fn (array $statement): array => (array) $statement['Resource'])
        ->all();

    expect($getResources)->toBe([
        'arn:aws:s3:::yolo-111111111111-testing-config/.env.environment.testing',
        'arn:aws:s3:::yolo-111111111111-testing-config/env/*',
    ]);
});

it('grants delete-only on the yolo-* object namespace for teardown, never a new read', function (): void {
    // destroy empties the per-app asset + config buckets (arbitrary builds/* keys, so
    // it can't be key-scoped) and removes the env-config claim/env files. Delete-only,
    // so the tier can clear a bucket without gaining any new read — the per-app
    // developer `.env` stays unreadable by admin.
    $objectDelete = collect((new AdminPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => (array) $statement['Action'] === ['s3:DeleteObject', 's3:DeleteObjectVersion']);

    expect($objectDelete)->not->toBeNull();
    expect($objectDelete['Resource'])->toBe('arn:aws:s3:::yolo-*/*');
    expect($objectDelete['Action'])->not->toContain('s3:GetObject');
});

it('grants the bucket empty+delete teardown, fenced to yolo-* (never the data bucket)', function (): void {
    // Emptying a versioned config bucket needs ListBucketVersions (ListBucket comes
    // from the observer read tier); DeleteBucket removes the regeneratable yolo-*
    // buckets. The app data bucket isn't yolo-named — and S3::deleteBucket hard-blocks
    // it by name — so this can never reach user data.
    $bucketLifecycle = collect((new AdminPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => in_array('s3:DeleteBucket', (array) $statement['Action'], true));

    expect($bucketLifecycle)->not->toBeNull();
    expect($bucketLifecycle['Resource'])->toBe('arn:aws:s3:::yolo-*');
    expect($bucketLifecycle['Action'])->toContain('s3:ListBucketVersions', 's3:DeleteBucket');
});
