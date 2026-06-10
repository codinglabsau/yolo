<?php

use Aws\Result;
use Aws\Command;
use Aws\Ssm\Exception\SsmException;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncSearchRuleStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncSearchRecordSetStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncMeilisearchClusterStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncMeilisearchServiceStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncMeilisearchLogGroupStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncMeilisearchMasterKeyStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncMeilisearchTargetGroupStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncMeilisearchExecutionRoleStep;
use Codinglabs\Yolo\Steps\Sync\App\SyncMeilisearchSecurityGroupStep;

function meilisearchManifest(array $overrides = []): void
{
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au',
        'tasks' => ['web' => []],
        'scout' => ['driver' => 'meilisearch'],
        ...$overrides,
    ]);
}

it('skips every meilisearch step when no scout driver is declared', function (string $step): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'domain' => 'codinglabs.com.au', 'tasks' => ['web' => []],
    ]);

    expect((new $step())([]))->toBe(StepResult::SKIPPED);
})->with([
    SyncMeilisearchMasterKeyStep::class,
    SyncMeilisearchLogGroupStep::class,
    SyncMeilisearchExecutionRoleStep::class,
    SyncMeilisearchSecurityGroupStep::class,
    SyncMeilisearchTargetGroupStep::class,
    SyncMeilisearchClusterStep::class,
    SyncMeilisearchServiceStep::class,
    SyncSearchRuleStep::class,
    SyncSearchRecordSetStep::class,
]);

it('skips every meilisearch step for an app-managed scout driver', function (string $step): void {
    meilisearchManifest(['scout' => ['driver' => 'algolia']]);

    expect((new $step())([]))->toBe(StepResult::SKIPPED);
})->with([
    SyncMeilisearchMasterKeyStep::class,
    SyncMeilisearchServiceStep::class,
    SyncSearchRuleStep::class,
    SyncSearchRecordSetStep::class,
]);

it('generates the master key as a SecureString parameter', function (): void {
    meilisearchManifest();

    $captured = [];
    bindMockSsmClient([
        'GetParameter' => new SsmException(
            'not found',
            new Command('GetParameter'),
            ['code' => 'ParameterNotFound'],
        ),
        'PutParameter' => new Result(),
    ], $captured);

    expect((new SyncMeilisearchMasterKeyStep())([]))->toBe(StepResult::CREATED);

    $put = collect($captured)->firstWhere('name', 'PutParameter');

    expect($put['args']['Name'])->toBe('yolo-testing-meilisearch-master-key')
        ->and($put['args']['Type'])->toBe('SecureString')
        // 32 random bytes hex-encoded — comfortably over Meilisearch's 16-byte minimum
        ->and($put['args']['Value'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(collect($put['args']['Tags'])->firstWhere('Key', 'yolo:scope')['Value'])->toBe('env');
});

it('reports the master key pending on a dry-run without writing', function (): void {
    meilisearchManifest();

    $captured = [];
    bindMockSsmClient([
        'GetParameter' => new SsmException(
            'not found',
            new Command('GetParameter'),
            ['code' => 'ParameterNotFound'],
        ),
    ], $captured);

    expect((new SyncMeilisearchMasterKeyStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(array_column($captured, 'name'))->not->toContain('PutParameter');
});

it('creates the security group and authorises the load balancer on 7700', function (): void {
    meilisearchManifest();

    $captured = [];
    bindMockEc2Client([
        'DescribeSecurityGroups' => [
            new Result(['SecurityGroups' => []]),  // first lookup → not found → create
            new Result(['SecurityGroups' => [     // re-lookup after create (repeats)
                ['GroupName' => 'yolo-testing-meilisearch-security-group', 'GroupId' => 'sg-meili'],
                ['GroupName' => 'yolo-testing-load-balancer-security-group', 'GroupId' => 'sg-lb'],
            ]]),
        ],
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'CreateSecurityGroup' => new Result(['GroupId' => 'sg-meili']),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncMeilisearchSecurityGroupStep())([]))->toBe(StepResult::CREATED);

    $authorise = collect($captured)->firstWhere('name', 'AuthorizeSecurityGroupIngress');

    expect($authorise['args']['IpPermissions'][0]['FromPort'])->toBe(7700)
        ->and($authorise['args']['IpPermissions'][0]['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-lb');
});

it('creates the env services cluster on FARGATE with container insights', function (): void {
    meilisearchManifest();

    $captured = [];
    bindRoutedEcsClient([
        'DescribeClusters' => new Result(['clusters' => []]),
        'CreateCluster' => new Result(),
    ], $captured);

    expect((new SyncMeilisearchClusterStep())([]))->toBe(StepResult::CREATED);

    $create = collect($captured)->firstWhere('name', 'CreateCluster');

    expect($create['args']['clusterName'])->toBe('yolo-testing-services')
        ->and($create['args']['capacityProviders'])->toBe(['FARGATE'])
        ->and($create['args']['settings'][0])->toBe(['name' => 'containerInsights', 'value' => 'enabled']);
});

it('creates the target group health-checking GET /health on 7700', function (): void {
    meilisearchManifest();

    $captured = [];
    $ec2 = [];

    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
    ], $ec2);

    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => []]),
        'CreateTargetGroup' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:tg:meili']]]),
        'ModifyTargetGroupAttributes' => new Result(),
    ], $captured);

    expect((new SyncMeilisearchTargetGroupStep())([]))->toBe(StepResult::CREATED);

    $create = collect($captured)->firstWhere('name', 'CreateTargetGroup');
    $attributes = collect($captured)->firstWhere('name', 'ModifyTargetGroupAttributes');

    expect($create['args']['Name'])->toBe('yolo-testing-meilisearch')
        ->and($create['args']['Port'])->toBe(7700)
        ->and($create['args']['TargetType'])->toBe('ip')
        ->and($create['args']['HealthCheckPath'])->toBe('/health')
        ->and($attributes['args']['Attributes'][0])->toBe(['Key' => 'deregistration_delay.timeout_seconds', 'Value' => '10']);
});

it('registers the pinned task definition and creates the singleton service', function (): void {
    meilisearchManifest();

    $captured = [];
    $ec2 = [];

    bindRoutedEcsClient([
        'DescribeServices' => new Result(['services' => []]),
        'RegisterTaskDefinition' => new Result(),
        'CreateService' => new Result(),
    ], $captured);

    bindMockIamClient([
        'yolo-testing-meilisearch-execution-role' => 'arn:aws:iam::111111111111:role/yolo-testing-meilisearch-execution-role',
    ]);

    bindMockEc2Client([
        'DescribeSubnets' => [
            new Result(['Subnets' => [['SubnetId' => 'subnet-a']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-b']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-c']]]),
        ],
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-meilisearch-security-group', 'GroupId' => 'sg-meili'],
        ]]),
    ], $ec2);

    $elb = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:tg:meili']]]),
    ], $elb);

    expect((new SyncMeilisearchServiceStep())([]))->toBe(StepResult::CREATED);

    $taskDefinition = collect($captured)->firstWhere('name', 'RegisterTaskDefinition')['args'];
    $service = collect($captured)->firstWhere('name', 'CreateService')['args'];

    $container = $taskDefinition['containerDefinitions'][0];

    expect($taskDefinition['family'])->toBe('yolo-testing-meilisearch')
        ->and($taskDefinition['cpu'])->toBe('1024')
        ->and($taskDefinition['memory'])->toBe('4096')
        ->and($container['image'])->toBe('getmeili/meilisearch:v1.46.1')
        ->and($container['secrets'][0])->toBe(['name' => 'MEILI_MASTER_KEY', 'valueFrom' => 'yolo-testing-meilisearch-master-key'])
        ->and(collect($container['environment'])->firstWhere('name', 'MEILI_ENV')['value'])->toBe('production')
        ->and($container['portMappings'][0]['containerPort'])->toBe(7700);

    expect($service['cluster'])->toBe('yolo-testing-services')
        ->and($service['desiredCount'])->toBe(1)
        // stop-then-start singleton: each task carries its own ephemeral index,
        // so a rollout must never briefly run two divergent copies
        ->and($service['deploymentConfiguration']['minimumHealthyPercent'])->toBe(0)
        ->and($service['deploymentConfiguration']['maximumPercent'])->toBe(100)
        ->and($service['deploymentConfiguration']['deploymentCircuitBreaker']['enable'])->toBeTrue()
        ->and($service['loadBalancers'][0])->toBe(['targetGroupArn' => 'arn:tg:meili', 'containerName' => 'meilisearch', 'containerPort' => 7700])
        ->and($service['networkConfiguration']['awsvpcConfiguration']['securityGroups'])->toBe(['sg-meili']);
});

it('never re-registers the task definition for an existing service — tags only', function (): void {
    meilisearchManifest();

    $captured = [];
    bindRoutedEcsClient([
        'DescribeServices' => new Result(['services' => [[
            'serviceName' => 'yolo-testing-meilisearch',
            'serviceArn' => 'arn:service:meili',
            'status' => 'ACTIVE',
        ]]]),
        'ListTagsForResource' => new Result(['tags' => [
            ['key' => 'Name', 'value' => 'yolo-testing-meilisearch'],
            ['key' => 'yolo:scope', 'value' => 'env'],
            ['key' => 'yolo:environment', 'value' => 'testing'],
        ]]),
    ], $captured);

    expect((new SyncMeilisearchServiceStep())([]))->toBe(StepResult::SYNCED);

    expect(array_column($captured, 'name'))
        ->not->toContain('RegisterTaskDefinition')
        ->not->toContain('CreateService')
        ->not->toContain('UpdateService');
});
