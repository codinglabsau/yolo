<?php

use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;

/**
 * Returns the first statement in the document whose Action list contains $action.
 */
function statementFor(array $document, string $action): array
{
    return collect($document['Statement'])
        ->first(fn (array $statement): bool => in_array($action, (array) $statement['Action'], true));
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
});

it('scopes the ECR push statement to this app\'s repository', function (): void {
    $statement = statementFor((new DeployerPolicy())->document(), 'ecr:PutImage');

    expect($statement['Resource'])->toBe('arn:aws:ecr:ap-southeast-2:111111111111:repository/yolo-testing-my-app');
    expect($statement['Action'])->toContain('ecr:InitiateLayerUpload', 'ecr:UploadLayerPart', 'ecr:CompleteLayerUpload');
});

it('grants RegisterTaskDefinition and the unscopeable describes on *', function (): void {
    $statement = statementFor((new DeployerPolicy())->document(), 'ecs:RegisterTaskDefinition');

    expect($statement['Resource'])->toBe('*');
    expect($statement['Action'])->toContain(
        'ecr:GetAuthorizationToken',
        'ecs:DescribeTaskDefinition',
        'elasticloadbalancing:DescribeTargetHealth',
        'ec2:DescribeSubnets',
        'sts:GetCallerIdentity',
    );
});

it('grants CloudFront ListDistributions on * so the build can resolve the asset distribution', function (): void {
    $statement = statementFor((new DeployerPolicy())->document(), 'cloudfront:ListDistributions');

    // CloudFront has no name-based lookup, so the build scans the account list
    // (a collection op with no resource-level scoping) to bake ASSET_URL.
    expect($statement['Resource'])->toBe('*');
});

it('grants iam:ListRoles on * so the task definition can resolve the task and execution role ARNs', function (): void {
    $statement = statementFor((new DeployerPolicy())->document(), 'iam:ListRoles');

    // The task-definition payload resolves the task + execution role ARNs by
    // scanning the account role list — an account-wide collection op with no
    // resource-level scoping.
    expect($statement['Resource'])->toBe('*');
});

it('grants ecs:TagResource gated to the RegisterTaskDefinition create action', function (): void {
    $statement = statementFor((new DeployerPolicy())->document(), 'ecs:TagResource');

    // The task-def payload registers with tags, which triggers a separate
    // tag-on-create authorization check. The create-action condition keeps the
    // grant to the register flow only — no retagging of arbitrary resources.
    expect($statement['Resource'])->toBe('*');
    expect($statement['Condition'])->toBe([
        'StringEquals' => ['ecs:CreateAction' => 'RegisterTaskDefinition'],
    ]);
});

it('scopes UpdateService and RunTask to this app\'s cluster resources', function (): void {
    $statement = statementFor((new DeployerPolicy())->document(), 'ecs:UpdateService');

    expect($statement['Resource'])->toEqualCanonicalizing([
        'arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-my-app',
        'arn:aws:ecs:ap-southeast-2:111111111111:service/yolo-testing-my-app/yolo-testing-my-app-web',
        'arn:aws:ecs:ap-southeast-2:111111111111:task-definition/yolo-testing-my-app-web:*',
        'arn:aws:ecs:ap-southeast-2:111111111111:task/yolo-testing-my-app/*',
    ]);
    expect($statement['Action'])->toContain('ecs:RunTask', 'ecs:DescribeServices');
});

it('grants ecs:ExecuteCommand on the same scoped cluster resources so yolo run can open an ECS Exec session', function (): void {
    $statement = statementFor((new DeployerPolicy())->document(), 'ecs:ExecuteCommand');

    // Same app-plane execution the deploy hooks already grant via RunTask —
    // scoped to this app's cluster and tasks, never account-wide.
    expect($statement['Resource'])->toContain(
        'arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-my-app',
        'arn:aws:ecs:ap-southeast-2:111111111111:task/yolo-testing-my-app/*',
    );
});

it('widens UpdateService scope to the standalone queue and scheduler services when extracted', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
    ]);

    $statement = statementFor((new DeployerPolicy())->document(), 'ecs:UpdateService');

    expect($statement['Resource'])->toContain(
        'arn:aws:ecs:ap-southeast-2:111111111111:service/yolo-testing-my-app/yolo-testing-my-app-queue',
        'arn:aws:ecs:ap-southeast-2:111111111111:task-definition/yolo-testing-my-app-queue:*',
        'arn:aws:ecs:ap-southeast-2:111111111111:service/yolo-testing-my-app/yolo-testing-my-app-scheduler',
        'arn:aws:ecs:ap-southeast-2:111111111111:task-definition/yolo-testing-my-app-scheduler:*',
    );
});

it('scopes PassRole to the per-app task role and shared execution role, passed only to ECS tasks', function (): void {
    $statement = statementFor((new DeployerPolicy())->document(), 'iam:PassRole');

    expect($statement['Resource'])->toBe([
        'arn:aws:iam::111111111111:role/yolo-testing-my-app-ecs-task-role',
        'arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role',
    ]);
    expect($statement['Condition'])->toBe([
        'StringEquals' => ['iam:PassedToService' => 'ecs-tasks.amazonaws.com'],
    ]);
});

it('grants write-only asset push on builds/* and read-only env-file pull, least-privilege', function (): void {
    $document = (new DeployerPolicy())->document();

    // Asset push (deploy): write-only on the per-deploy builds/ prefix — no read,
    // no ListBucket. s3:PutObject covers the whole multipart upload chain.
    $assets = collect($document['Statement'])
        ->first(fn (array $statement): bool => $statement['Resource'] === 'arn:aws:s3:::yolo-111111111111-testing-my-app-assets/builds/*');
    expect($assets)->not->toBeNull();
    expect($assets['Action'])->toBe([
        's3:PutObject',
        's3:AbortMultipartUpload',
        's3:ListMultipartUploadParts',
    ]);

    // Env-file pull (build): read-only on exactly this app's .env.{env} object.
    $envFile = collect($document['Statement'])
        ->first(fn (array $statement): bool => $statement['Resource'] === 'arn:aws:s3:::yolo-111111111111-testing-my-app-config/.env.testing');
    expect($envFile)->not->toBeNull();
    expect($envFile['Action'])->toBe(['s3:GetObject']);

    // No bucket-level S3 grant survives the tightening, and the asset/config
    // buckets are never reachable at the bucket root or whole-object level.
    $allActions = collect($document['Statement'])->flatMap(fn (array $statement): array => (array) $statement['Action']);
    expect($allActions)->not->toContain('s3:ListBucket')
        ->not->toContain('s3:ListBucketMultipartUploads')
        ->not->toContain('s3:GetBucketLocation');

    $allResources = collect($document['Statement'])->flatMap(fn (array $statement): array => (array) $statement['Resource']);
    expect($allResources)->not->toContain('arn:aws:s3:::yolo-111111111111-testing-my-app-assets/*')
        ->not->toContain('arn:aws:s3:::yolo-111111111111-testing-my-app-assets')
        ->not->toContain('arn:aws:s3:::yolo-111111111111-testing-my-app-config/*')
        ->not->toContain('arn:aws:s3:::yolo-111111111111-testing-my-app-config');
});

it('grants read on this app\'s env-side .env in the env config bucket, scoped to the object not the bucket', function (): void {
    // ConfigureEnvAndVersionStep merges env/.env.{app} (the app's YOLO-minted
    // Typesense key) into the built env, so the deployer needs read on exactly
    // that object — never the env-shared `.env` (the cluster admin key) or a
    // sibling app's env-side file.
    $envSide = collect((new DeployerPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => $statement['Resource'] === 'arn:aws:s3:::yolo-111111111111-testing-config/env/.env.my-app');

    expect($envSide)->not->toBeNull();
    expect($envSide['Action'])->toBe(['s3:GetObject']);

    // Never the env-shared `.env` nor the whole env/ prefix.
    $allResources = collect((new DeployerPolicy())->document()['Statement'])
        ->flatMap(fn (array $statement): array => (array) $statement['Resource']);

    expect($allResources)
        ->not->toContain('arn:aws:s3:::yolo-111111111111-testing-config/.env.environment.testing')
        ->not->toContain('arn:aws:s3:::yolo-111111111111-testing-config/env/*');
});

it('grants object access on this app\'s claim file in the env config bucket, scoped to the object not the bucket', function (): void {
    // PublishAppManifestStep reads then writes apps/{app}.yml in the env config
    // bucket on every deploy. The grant must be scoped to exactly this app's
    // claim object — never the bucket root — so the deployer can't reach the
    // env-shared `.env` or env manifest that live in the same bucket.
    $claimStatement = collect((new DeployerPolicy())->document()['Statement'])
        ->first(fn (array $statement): bool => $statement['Resource'] === 'arn:aws:s3:::yolo-111111111111-testing-config/apps/my-app.yml');

    expect($claimStatement)->not->toBeNull();
    expect($claimStatement['Action'])->toBe(['s3:GetObject', 's3:PutObject']);

    // The env config bucket root is never granted at the object or bucket level —
    // that read is the permission gating env-secret control. (The read surface the
    // sync-check gate needs lives in the separate ObserverPolicy policy, scoped to
    // non-secret config — never granted here.)
    $allResources = collect((new DeployerPolicy())->document()['Statement'])
        ->flatMap(fn (array $statement): array => (array) $statement['Resource']);

    expect($allResources)->not->toContain('arn:aws:s3:::yolo-111111111111-testing-config');
    expect($allResources)->not->toContain('arn:aws:s3:::yolo-111111111111-testing-config/*');
});

it('grants elasticache:DescribeReplicationGroups on * when the app uses the redis cache store', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'cache' => ['store' => 'redis'],
    ]);

    // The build bakes REDIS_HOST by reading the Valkey primary endpoint
    // (ConfigureEnvAndVersionStep). DescribeReplicationGroups has no
    // resource-level scoping, so it's granted on "*".
    $statement = statementFor((new DeployerPolicy())->document(), 'elasticache:DescribeReplicationGroups');

    expect($statement['Resource'])->toBe('*');
});

it('omits elasticache permissions when the app opts out of the shared Valkey cache', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'cache' => ['store' => 'file'],
    ]);

    $actions = collect((new DeployerPolicy())->document()['Statement'])
        ->flatMap(fn (array $statement): array => (array) $statement['Action']);

    expect($actions)->not->toContain('elasticache:DescribeReplicationGroups');
});

it('omits Route 53 permissions for a headless app with no domain', function (): void {
    $document = (new DeployerPolicy())->document();

    $actions = collect($document['Statement'])->flatMap(fn (array $statement): array => (array) $statement['Action']);

    expect($actions)->not->toContain('route53:ChangeResourceRecordSets');
});

it('grants Route 53 record changes scoped to the hosted-zone resource type when a domain is set', function (): void {
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    $document = (new DeployerPolicy())->document();

    // No live zone lookup — the document is pure, so it never couples the IAM
    // sync phase to the zone the Solo phase creates.
    expect(statementFor($document, 'route53:ListHostedZones')['Resource'])->toBe('*');
    expect(statementFor($document, 'route53:ChangeResourceRecordSets')['Resource'])
        ->toBe('arn:aws:route53:::hostedzone/*');
    expect(statementFor($document, 'route53:GetChange')['Resource'])
        ->toBe('arn:aws:route53:::change/*');
});

it('includes Route 53 statements for a subdomain canary', function (): void {
    writeManifest([
        'domain' => 'fargate.example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    $document = (new DeployerPolicy())->document();

    expect(statementFor($document, 'route53:ChangeResourceRecordSets'))->not->toBeNull();
});
