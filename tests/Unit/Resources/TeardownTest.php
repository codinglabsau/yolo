<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Aws\Acm\AcmClient;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use Aws\Route53\Route53Client;
use GuzzleHttp\Promise\Create;
use Aws\CloudFront\CloudFrontClient;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Sqs\Queue;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;
use Codinglabs\Yolo\Resources\S3\S3ConfigBucket;
use Codinglabs\Yolo\Resources\Acm\SslCertificate;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;
use Codinglabs\Yolo\Resources\Iam\DeployersGroup;
use Codinglabs\Yolo\Resources\Route53\HostedZone;
use Codinglabs\Yolo\Resources\Iam\AppObserverRole;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Resources\CloudWatch\QueueAlarm;
use Codinglabs\Yolo\Resources\Iam\AppObserverPolicy;
use Codinglabs\Yolo\Resources\Iam\AppObserversGroup;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;
use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'example.com',
    ]);
});

/**
 * Generic command-routed mock for clients without a Pest-wide binder
 * (cloudWatchLogs, route53, cloudFront, acm). Mirrors bindRoutedS3Client: a
 * command value may be a single Result/Throwable or a queue array.
 *
 * @param  array<string, Result|Throwable|array<int, Result|Throwable>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindTeardownRoutedClient(string $binding, string $clientClass, array $byCommand, array &$captured): void
{
    $mock = new class($byCommand, $captured) extends MockHandler
    {
        /** @var array<string, int> */
        private array $cursors = [];

        public function __construct(protected array $byCommand, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $name = $cmd->getName();
            $this->captured[] = ['name' => $name, 'args' => $cmd->toArray()];

            $entry = $this->byCommand[$name] ?? new Result();

            if (is_array($entry)) {
                $index = min($this->cursors[$name] ?? 0, count($entry) - 1);
                $this->cursors[$name] = $index + 1;
                $entry = $entry[$index];
            }

            return $entry instanceof Throwable
                ? Create::rejectionFor($entry)
                : Create::promiseFor($entry);
        }
    };

    Helpers::app()->instance($binding, new $clientClass([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

it('empties an unversioned asset bucket before deleting it', function (): void {
    $bucket = new AssetBucket();

    $captured = [];
    bindRoutedS3Client([
        'ListObjectsV2' => new Result(['Contents' => [['Key' => 'builds/1/app.js'], ['Key' => 'builds/1/app.css']]]),
        'DeleteObjects' => new Result(['Deleted' => []]),
        'DeleteBucket' => new Result([]),
    ], $captured);

    $bucket->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('ListObjectsV2')->toContain('DeleteObjects')->toContain('DeleteBucket');

    // Objects are purged before the bucket is removed.
    expect(array_search('DeleteObjects', $names, true))->toBeLessThan(array_search('DeleteBucket', $names, true));

    $delete = collect($captured)->firstWhere('name', 'DeleteObjects');
    expect($delete['args']['Delete']['Objects'])->toBe([['Key' => 'builds/1/app.js'], ['Key' => 'builds/1/app.css']]);
});

it('clears versions and delete markers from the versioned config bucket before deleting it', function (): void {
    $bucket = new S3ConfigBucket();

    $captured = [];
    bindRoutedS3Client([
        'ListObjectVersions' => new Result([
            'Versions' => [['Key' => '.env', 'VersionId' => 'v1']],
            'DeleteMarkers' => [['Key' => '.env', 'VersionId' => 'dm1']],
        ]),
        'DeleteObjects' => new Result(['Deleted' => []]),
        'DeleteBucket' => new Result([]),
    ], $captured);

    $bucket->delete();

    $delete = collect($captured)->firstWhere('name', 'DeleteObjects');
    expect($delete['args']['Delete']['Objects'])->toBe([
        ['Key' => '.env', 'VersionId' => 'v1'],
        ['Key' => '.env', 'VersionId' => 'dm1'],
    ]);
    expect(array_column($captured, 'name'))->toContain('DeleteBucket');
});

it('detaches managed and inline policies before deleting a role', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'ListAttachedRolePolicies' => new Result(['AttachedPolicies' => [['PolicyArn' => 'arn:aws:iam::aws:policy/foo']]]),
        'ListRolePolicies' => new Result(['PolicyNames' => ['inline-1']]),
    ], $captured);

    (new DeployerRole())->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('DetachRolePolicy')->toContain('DeleteRolePolicy')->toContain('DeleteRole');
    expect(array_search('DetachRolePolicy', $names, true))->toBeLessThan(array_search('DeleteRole', $names, true));
});

it('detaches entities and prunes non-default versions before deleting a policy', function (): void {
    $policy = new DeployerPolicy();

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [['PolicyName' => $policy->name(), 'Arn' => 'arn:aws:iam::111111111111:policy/dep']]]),
        'ListEntitiesForPolicy' => new Result(['PolicyRoles' => [['RoleName' => 'some-role']], 'PolicyGroups' => [], 'PolicyUsers' => []]),
        'ListPolicyVersions' => new Result(['Versions' => [
            ['VersionId' => 'v1', 'IsDefaultVersion' => true],
            ['VersionId' => 'v2', 'IsDefaultVersion' => false],
        ]]),
    ], $captured);

    $policy->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('DetachRolePolicy')->toContain('DeletePolicyVersion')->toContain('DeletePolicy');

    // Only the non-default version is pruned.
    expect(collect($captured)->firstWhere('name', 'DeletePolicyVersion')['args']['VersionId'])->toBe('v2');
});

it('empties members and policies before deleting a group', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'GetGroup' => new Result(['Users' => [['UserName' => 'alice']]]),
        'ListAttachedGroupPolicies' => new Result(['AttachedPolicies' => [['PolicyArn' => 'arn:aws:iam::aws:policy/bar']]]),
        'ListGroupPolicies' => new Result(['PolicyNames' => []]),
    ], $captured);

    (new DeployersGroup())->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('RemoveUserFromGroup')->toContain('DetachGroupPolicy')->toContain('DeleteGroup');
});

it('drains and force-deletes every service before deleting the cluster', function (): void {
    $captured = [];
    bindRoutedEcsClient([
        'ListServices' => new Result(['serviceArns' => ['arn:svc:web', 'arn:svc:queue']]),
    ], $captured);

    (new EcsCluster())->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('DeleteService')->toContain('DeleteCluster');
    expect(array_search('DeleteCluster', $names, true))->toBeGreaterThan(array_search('DeleteService', $names, true));
    expect(collect($captured)->firstWhere('name', 'DeleteService')['args']['force'])->toBeTrue();
});

it('force-deletes a service to drain its tasks', function (): void {
    $captured = [];
    bindRoutedEcsClient([], $captured);

    (new EcsService(ServerGroup::WEB))->delete();

    $delete = collect($captured)->firstWhere('name', 'DeleteService');
    expect($delete['args']['force'])->toBeTrue()
        ->and($delete['args']['service'])->toBe('yolo-testing-my-app-web');
});

it('force-deletes the image repository and its images', function (): void {
    $captured = [];
    bindRoutedEcrClient([], $captured);

    (new EcrRepository())->delete();

    $delete = collect($captured)->firstWhere('name', 'DeleteRepository');
    expect($delete['args']['force'])->toBeTrue();
});

it('resolves the queue url then deletes the queue', function (): void {
    $captured = [];
    bindMockSqsClient([
        'ListQueues' => new Result(['QueueUrls' => ['https://sqs/123/yolo-testing-my-app']]),
        'GetQueueAttributes' => new Result(['Attributes' => ['QueueArn' => 'arn:aws:sqs:ap-southeast-2:111111111111:yolo-testing-my-app']]),
    ], $captured);

    (new Queue('yolo-testing-my-app'))->delete();

    expect(collect($captured)->firstWhere('name', 'DeleteQueue')['args']['QueueUrl'])
        ->toBe('https://sqs/123/yolo-testing-my-app');
});

it('resolves the task security group id then deletes it', function (): void {
    $group = new EcsTaskSecurityGroup();

    $captured = [];
    bindMockEc2Client([
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [['GroupName' => $group->name(), 'GroupId' => 'sg-abc']]]),
    ], $captured);

    $group->delete();

    expect(collect($captured)->firstWhere('name', 'DeleteSecurityGroup')['args']['GroupId'])->toBe('sg-abc');
});

it('deletes the dashboard and the queue alarm by name', function (): void {
    $captured = [];
    bindMockCloudWatchClient([], $captured);

    (new Dashboard())->delete();
    (new QueueAlarm('yolo-testing-my-app-queue-backlog', 'yolo-testing-my-app'))->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('DeleteDashboards')->toContain('DeleteAlarms');
    expect(collect($captured)->firstWhere('name', 'DeleteAlarms')['args']['AlarmNames'])->toBe(['yolo-testing-my-app-queue-backlog']);
});

it('deletes the task log group, tolerating an already-absent group', function (): void {
    $captured = [];
    bindTeardownRoutedClient('cloudWatchLogs', CloudWatchLogsClient::class, [], $captured);

    (new TaskLogGroup())->delete();

    expect(array_column($captured, 'name'))->toContain('DeleteLogGroup');
});

it('deletes the target group', function (): void {
    $captured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupName' => 'yolo-testing-my-app', 'TargetGroupArn' => 'arn:tg']]]),
    ], $captured);

    (new TargetGroup())->delete();

    expect(collect($captured)->firstWhere('name', 'DeleteTargetGroup')['args']['TargetGroupArn'])->toBe('arn:tg');
});

it('finds and deletes the ssl certificate', function (): void {
    $captured = [];
    bindTeardownRoutedClient('acm', AcmClient::class, [
        'ListCertificates' => new Result(['CertificateSummaryList' => [
            ['DomainName' => 'example.com', 'CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/abc-123'],
        ]]),
    ], $captured);

    (new SslCertificate('example.com'))->delete();

    expect(collect($captured)->firstWhere('name', 'DeleteCertificate')['args']['CertificateArn'])
        ->toBe('arn:aws:acm:ap-southeast-2:111111111111:certificate/abc-123');
});

it('clears records then deletes a hosted zone this environment owns', function (): void {
    $captured = [];
    bindTeardownRoutedClient('route53', Route53Client::class, [
        'ListHostedZones' => new Result(['HostedZones' => [['Id' => '/hostedzone/Z123', 'Name' => 'example.com.']]]),
        'ListTagsForResource' => new Result(['ResourceTagSet' => ['Tags' => [['Key' => 'yolo:environment', 'Value' => 'testing']]]]),
        'ListResourceRecordSets' => new Result(['ResourceRecordSets' => [
            ['Name' => 'example.com.', 'Type' => 'NS', 'TTL' => 172800, 'ResourceRecords' => [['Value' => 'ns-1']]],
            ['Name' => 'example.com.', 'Type' => 'SOA', 'TTL' => 900, 'ResourceRecords' => [['Value' => 'soa']]],
            ['Name' => 'www.example.com.', 'Type' => 'A', 'TTL' => 60, 'ResourceRecords' => [['Value' => '1.2.3.4']]],
        ]]),
    ], $captured);

    (new HostedZone('example.com'))->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('ChangeResourceRecordSets')->toContain('DeleteHostedZone');

    // Only the non-apex-NS/SOA record is removed.
    $change = collect($captured)->firstWhere('name', 'ChangeResourceRecordSets');
    expect($change['args']['ChangeBatch']['Changes'])->toHaveCount(1)
        ->and($change['args']['ChangeBatch']['Changes'][0]['ResourceRecordSet']['Name'])->toBe('www.example.com.');
});

it('never deletes a hosted zone a sibling environment owns', function (): void {
    $captured = [];
    bindTeardownRoutedClient('route53', Route53Client::class, [
        'ListHostedZones' => new Result(['HostedZones' => [['Id' => '/hostedzone/Z123', 'Name' => 'example.com.']]]),
        'ListTagsForResource' => new Result(['ResourceTagSet' => ['Tags' => [['Key' => 'yolo:environment', 'Value' => 'production']]]]),
    ], $captured);

    (new HostedZone('example.com'))->delete();

    expect(array_column($captured, 'name'))->not->toContain('DeleteHostedZone');
});

it('detaches and deletes every app IAM role', function (string $class): void {
    $captured = [];
    bindRoutedIamClient([
        'ListAttachedRolePolicies' => new Result(['AttachedPolicies' => [['PolicyArn' => 'arn:aws:iam::aws:policy/foo']]]),
        'ListRolePolicies' => new Result(['PolicyNames' => ['inline-1']]),
    ], $captured);

    (new $class())->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('DetachRolePolicy')->toContain('DeleteRolePolicy')->toContain('DeleteRole');
})->with([
    AppObserverRole::class,
    EcsTaskRole::class,
]);

it('detaches entities, prunes versions and deletes every app IAM policy', function (string $class): void {
    $policy = new $class();

    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [['PolicyName' => $policy->name(), 'Arn' => 'arn:aws:iam::111111111111:policy/p']]]),
        'ListEntitiesForPolicy' => new Result(['PolicyRoles' => [['RoleName' => 'r']], 'PolicyGroups' => [['GroupName' => 'g']], 'PolicyUsers' => [['UserName' => 'u']]]),
        'ListPolicyVersions' => new Result(['Versions' => [['VersionId' => 'v1', 'IsDefaultVersion' => true], ['VersionId' => 'v2', 'IsDefaultVersion' => false]]]),
    ], $captured);

    $policy->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('DetachRolePolicy')->toContain('DetachGroupPolicy')->toContain('DetachUserPolicy')
        ->toContain('DeletePolicyVersion')->toContain('DeletePolicy');
})->with([
    AppObserverPolicy::class,
    EcsTaskPolicy::class,
]);

it('empties and deletes the app observers group', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'GetGroup' => new Result(['Users' => [['UserName' => 'bob']]]),
        'ListAttachedGroupPolicies' => new Result(['AttachedPolicies' => [['PolicyArn' => 'arn:aws:iam::aws:policy/baz']]]),
        'ListGroupPolicies' => new Result(['PolicyNames' => ['inline-grp']]),
    ], $captured);

    (new AppObserversGroup())->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('RemoveUserFromGroup')->toContain('DetachGroupPolicy')
        ->toContain('DeleteGroupPolicy')->toContain('DeleteGroup');
});

it('disables the distribution, waits, then deletes it', function (): void {
    $captured = [];
    bindTeardownRoutedClient('cloudFront', CloudFrontClient::class, [
        'ListDistributions' => new Result(['DistributionList' => ['Items' => [[
            'Id' => 'D123',
            'Comment' => (new AssetDistribution())->name(),
            'ARN' => 'arn:cf:D123',
            'DomainName' => 'd123.cloudfront.net',
        ]]]]),
        'GetDistributionConfig' => new Result(['DistributionConfig' => [
            'CallerReference' => 'x',
            'Comment' => (new AssetDistribution())->name(),
            'Enabled' => true,
            'Origins' => ['Quantity' => 1, 'Items' => [['Id' => 'asset-bucket', 'DomainName' => 'b.s3.amazonaws.com', 'S3OriginConfig' => ['OriginAccessIdentity' => '']]]],
            'DefaultCacheBehavior' => ['TargetOriginId' => 'asset-bucket', 'ViewerProtocolPolicy' => 'redirect-to-https'],
        ], 'ETag' => 'E1']),
        'UpdateDistribution' => new Result(['ETag' => 'E2']),
        'GetDistribution' => new Result(['Distribution' => ['Status' => 'Deployed']]),
        'DeleteDistribution' => new Result([]),
    ], $captured);

    (new AssetDistribution())->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('UpdateDistribution')->toContain('DeleteDistribution');

    // The update disables the distribution before the delete.
    expect(collect($captured)->firstWhere('name', 'UpdateDistribution')['args']['DistributionConfig']['Enabled'])->toBeFalse();
    expect(array_search('UpdateDistribution', $names, true))->toBeLessThan(array_search('DeleteDistribution', $names, true));
});
