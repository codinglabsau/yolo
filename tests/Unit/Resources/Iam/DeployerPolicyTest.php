<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use Aws\Route53\Route53Client;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;

/**
 * Returns the first statement in the document whose Action list contains $action.
 */
function statementFor(array $document, string $action): array
{
    return collect($document['Statement'])
        ->first(fn (array $statement) => in_array($action, (array) $statement['Action'], true));
}

function bindMockRoute53Client(array $hostedZones): void
{
    $result = new Result(['HostedZones' => $hostedZones]);

    $mock = new class($result) extends MockHandler
    {
        public function __construct(protected Result $result) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor($this->result);
        }
    };

    Helpers::app()->instance('route53', new Route53Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'deployer' => ['repository' => 'my-org/my-repo'],
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
        'deployer' => ['repository' => 'my-org/my-repo'],
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

it('scopes Route 53 record changes to the resolved hosted zone when a domain is set', function () {
    writeManifest([
        'apex' => 'example.com',
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'deployer' => ['repository' => 'my-org/my-repo'],
    ]);

    bindMockRoute53Client([
        ['Name' => 'example.com.', 'Id' => '/hostedzone/Z123ABC'],
    ]);

    $document = (new DeployerPolicy())->document();

    expect(statementFor($document, 'route53:ListHostedZones')['Resource'])->toBe('*');
    expect(statementFor($document, 'route53:ChangeResourceRecordSets')['Resource'])
        ->toBe('arn:aws:route53:::hostedzone/Z123ABC');
    expect(statementFor($document, 'route53:GetChange')['Resource'])
        ->toBe('arn:aws:route53:::change/*');
});
