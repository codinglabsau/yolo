<?php

use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;

/**
 * Returns the first statement in the document whose Action list contains $action.
 */
function statementFor(array $document, string $action): array
{
    return collect($document['Statement'])
        ->first(fn (array $statement) => in_array($action, (array) $statement['Action'], true));
}

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

it('scopes the ECR push statement to this app\'s repository', function () {
    $statement = statementFor((new DeployerPolicy())->document(), 'ecr:PutImage');

    expect($statement['Resource'])->toBe('arn:aws:ecr:ap-southeast-2:111111111111:repository/my-app');
    expect($statement['Action'])->toContain('ecr:InitiateLayerUpload', 'ecr:UploadLayerPart', 'ecr:CompleteLayerUpload');
});

it('grants RegisterTaskDefinition and the unscopeable describes on *', function () {
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

it('scopes UpdateService and RunTask to this app\'s cluster resources', function () {
    $statement = statementFor((new DeployerPolicy())->document(), 'ecs:UpdateService');

    expect($statement['Resource'])->toEqualCanonicalizing([
        'arn:aws:ecs:ap-southeast-2:111111111111:cluster/yolo-testing-my-app',
        'arn:aws:ecs:ap-southeast-2:111111111111:service/yolo-testing-my-app/yolo-testing-my-app-web',
        'arn:aws:ecs:ap-southeast-2:111111111111:task-definition/yolo-testing-my-app-web:*',
        'arn:aws:ecs:ap-southeast-2:111111111111:task/yolo-testing-my-app/*',
    ]);
    expect($statement['Action'])->toContain('ecs:RunTask', 'ecs:DescribeServices');
});

it('scopes PassRole to the task and execution roles, passed only to ECS tasks', function () {
    $statement = statementFor((new DeployerPolicy())->document(), 'iam:PassRole');

    expect($statement['Resource'])->toBe([
        'arn:aws:iam::111111111111:role/yolo-testing-ecs-task-role',
        'arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role',
    ]);
    expect($statement['Condition'])->toBe([
        'StringEquals' => ['iam:PassedToService' => 'ecs-tasks.amazonaws.com'],
    ]);
});

it('honours manifest task-role and execution-role overrides for PassRole', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => [
            'task-role' => 'arn:aws:iam::111111111111:role/custom-task',
            'execution-role' => 'arn:aws:iam::111111111111:role/custom-exec',
        ]],
    ]);

    $statement = statementFor((new DeployerPolicy())->document(), 'iam:PassRole');

    expect($statement['Resource'])->toBe([
        'arn:aws:iam::111111111111:role/custom-task',
        'arn:aws:iam::111111111111:role/custom-exec',
    ]);
});

it('grants S3 object and bucket access on the asset and artefacts buckets', function () {
    $document = (new DeployerPolicy())->document();

    $objects = statementFor($document, 's3:PutObject');
    expect($objects['Resource'])->toBe([
        'arn:aws:s3:::yolo-testing-my-app-assets/*',
        'arn:aws:s3:::yolo-testing-my-app-artefacts/*',
    ]);

    $buckets = statementFor($document, 's3:ListBucket');
    expect($buckets['Resource'])->toBe([
        'arn:aws:s3:::yolo-testing-my-app-assets',
        'arn:aws:s3:::yolo-testing-my-app-artefacts',
    ]);
});

it('omits Route 53 permissions for a headless app with no domain', function () {
    $document = (new DeployerPolicy())->document();

    $actions = collect($document['Statement'])->flatMap(fn (array $statement) => (array) $statement['Action']);

    expect($actions)->not->toContain('route53:ChangeResourceRecordSets');
});

it('grants Route 53 record changes scoped to the hosted-zone resource type when a domain is set', function () {
    writeManifest([
        'apex' => 'example.com',
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
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

it('includes Route 53 statements for a subdomain canary (domain only, no apex)', function () {
    writeManifest([
        'domain' => 'fargate.example.com',
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);

    $document = (new DeployerPolicy())->document();

    expect(statementFor($document, 'route53:ChangeResourceRecordSets'))->not->toBeNull();
});
