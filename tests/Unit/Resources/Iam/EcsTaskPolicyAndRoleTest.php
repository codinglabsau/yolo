<?php

use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('describes the ECS task policy with the four ssmmessages exec permissions', function (): void {
    $document = (new EcsTaskPolicy())->document();

    expect($document['Version'])->toBe('2012-10-17');
    expect($document['Statement'])->toHaveCount(3);
    expect($document['Statement'][0])->toMatchArray([
        'Effect' => 'Allow',
        'Resource' => '*',
    ]);

    expect($document['Statement'][0]['Action'])->toEqualCanonicalizing([
        'ssmmessages:CreateControlChannel',
        'ssmmessages:CreateDataChannel',
        'ssmmessages:OpenControlChannel',
        'ssmmessages:OpenDataChannel',
    ]);
});

it('grants SQS access scoped to this app\'s own queues only', function (): void {
    $statement = (new EcsTaskPolicy())->document()['Statement'][1];

    expect($statement['Effect'])->toBe('Allow');
    // The solo queue (exact) plus landlord/per-tenant queues (prefix) — never a
    // sibling app's, which the old env-wide `yolo-testing-*` grant leaked.
    expect($statement['Resource'])->toBe([
        'arn:aws:sqs:ap-southeast-2:111111111111:yolo-testing-my-app',
        'arn:aws:sqs:ap-southeast-2:111111111111:yolo-testing-my-app-*',
    ]);
    expect($statement['Action'])->toContain('sqs:ReceiveMessage', 'sqs:DeleteMessage', 'sqs:SendMessage', 'sqs:ChangeMessageVisibility');
});

it('scopes the task role and policy to this app (carrying the yolo:app owner tag)', function (): void {
    expect((new EcsTaskRole())->name())->toBe('yolo-testing-my-app-ecs-task-role');
    expect((new EcsTaskPolicy())->name())->toBe('yolo-testing-my-app-ecs-task-policy');
    expect((new EcsTaskRole())->tags())->toMatchArray(['yolo:scope' => 'app', 'yolo:app' => 'my-app']);
    expect((new EcsTaskPolicy())->tags())->toMatchArray(['yolo:scope' => 'app', 'yolo:app' => 'my-app']);
});

it('grants SES send scoped to this region\'s verified identities', function (): void {
    $statement = (new EcsTaskPolicy())->document()['Statement'][2];

    expect($statement['Effect'])->toBe('Allow');
    expect($statement['Resource'])->toBe('arn:aws:ses:ap-southeast-2:111111111111:identity/*');
    expect($statement['Action'])->toEqualCanonicalizing([
        'ses:SendRawEmail',
        'ses:SendEmail',
    ]);
});

it('trusts the ecs-tasks service in the ECS task assume role policy', function (): void {
    expect((new EcsTaskRole())->assumeRolePolicyDocument())->toBe([
        'Version' => '2012-10-17',
        'Statement' => [
            [
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'ecs-tasks.amazonaws.com'],
                'Action' => 'sts:AssumeRole',
            ],
        ],
    ]);
});

it('grants no S3 access when the manifest declares no data bucket', function (): void {
    $resources = collect((new EcsTaskPolicy())->document()['Statement'])->pluck('Resource')->flatten();

    expect($resources->filter(fn ($arn): bool => str_starts_with((string) $arn, 'arn:aws:s3:::')))->toBeEmpty();
});

it('grants read+write on the declared data bucket, scoped to that bucket only', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'bucket' => 'my-app-uploads',
    ]);

    $statements = (new EcsTaskPolicy())->document()['Statement'];

    // The three baseline statements (ssmmessages, SQS, SES) plus the two bucket ones.
    expect($statements)->toHaveCount(5);

    $object = collect($statements)->firstWhere('Resource', 'arn:aws:s3:::my-app-uploads/*');
    expect($object['Effect'])->toBe('Allow');
    expect($object['Action'])->toEqualCanonicalizing([
        's3:GetObject',
        's3:GetObjectAcl',
        's3:PutObject',
        's3:PutObjectAcl',
        's3:DeleteObject',
        's3:AbortMultipartUpload',
        's3:ListMultipartUploadParts',
    ]);

    $bucket = collect($statements)->firstWhere('Resource', 'arn:aws:s3:::my-app-uploads');
    expect($bucket['Effect'])->toBe('Allow');
    expect($bucket['Action'])->toEqualCanonicalizing([
        's3:ListBucket',
        's3:ListBucketMultipartUploads',
        's3:GetBucketLocation',
    ]);
});

it('grants the task role IVS access when the app consumes the ivs service', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['ivs'],
    ]);

    $statements = (new EcsTaskPolicy())->document()['Statement'];

    expect($statements)->toHaveCount(4);

    $ivs = collect($statements)->firstWhere('Action', ['ivs:*']);
    expect($ivs['Effect'])->toBe('Allow');
    // Channels/stream keys are created by the app at runtime — no stable
    // ARNs to scope to, so the grant is service-wide.
    expect($ivs['Resource'])->toBe('*');
});

it('grants no IVS access when the app does not consume the ivs service', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    $actions = collect((new EcsTaskPolicy())->document()['Statement'])->pluck('Action')->flatten();

    expect($actions)->not->toContain('ivs:*');
});
