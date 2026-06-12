<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Aws\Sns\SnsClient;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use Aws\Route53\Route53Client;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Aws\Route53;
use Aws\CloudWatch\CloudWatchClient;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\ServiceDiscovery\ServiceDiscoveryClient;
use Codinglabs\Yolo\Resources\Ecs\TypesenseService;
use Codinglabs\Yolo\Resources\ElbV2\SearchTargetGroup;
use Codinglabs\Yolo\Resources\ElbV2\SearchListenerRule;
use Codinglabs\Yolo\Resources\CloudWatch\TypesenseAlarm;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TypesenseLogGroup;
use Codinglabs\Yolo\Aws\ServiceDiscovery as ServiceDiscoveryApi;
use Codinglabs\Yolo\Resources\ServiceDiscovery\PrivateDnsNamespace;
use Codinglabs\Yolo\Resources\ServiceDiscovery\TypesenseDiscoveryService;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

/**
 * Generic routed mock for clients without a Pest-wide binder — same contract
 * as bindRoutedS3Client.
 *
 * @param  array<string, Result|Throwable|array<int, Result|Throwable>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindTypesenseRoutedClient(string $binding, string $clientClass, array $byCommand, array &$captured): void
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

it('the typesense log group creates with tags and reconciles its hardcoded retention', function (): void {
    $captured = [];
    bindTypesenseRoutedClient('cloudWatchLogs', CloudWatchLogsClient::class, [
        'DescribeLogGroups' => new Result(['logGroups' => [[
            'logGroupName' => 'yolo-testing-typesense',
            'retentionInDays' => 30,
        ]]]),
    ], $captured);

    $logGroup = new TypesenseLogGroup();

    expect($logGroup->name())->toBe('yolo-testing-typesense')
        ->and($logGroup->exists())->toBeTrue()
        ->and($logGroup->arn())->toContain('log-group:yolo-testing-typesense');

    // Live retention (30) drifts from the hardcoded 14 — reconciled on apply.
    $changes = $logGroup->synchroniseConfiguration(apply: true);

    expect($changes)->toHaveCount(1);

    $retention = collect($captured)->firstWhere('name', 'PutRetentionPolicy');
    expect($retention['args']['retentionInDays'])->toBe(14);

    $logGroup->delete();
    expect(array_column($captured, 'name'))->toContain('DeleteLogGroup');
});

it('a typesense alarm renders the full payload and upserts on drift', function (): void {
    $captured = [];
    bindTypesenseRoutedClient('cloudWatch', CloudWatchClient::class, [
        'DescribeAlarms' => new Result(['MetricAlarms' => [[
            'AlarmName' => 'yolo-testing-typesense-quorum-lost',
            'AlarmArn' => 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:yolo-testing-typesense-quorum-lost',
            'Threshold' => 1.0, // drifted: desired is 2
            'EvaluationPeriods' => 1,
            'ComparisonOperator' => 'LessThanThreshold',
            'MetricName' => 'HealthyHostCount',
        ]]]),
    ], $captured);

    $snsCaptured = [];
    bindTypesenseRoutedClient('sns', SnsClient::class, [
        'ListTopics' => new Result(['Topics' => [
            ['TopicArn' => 'arn:aws:sns:ap-southeast-2:111111111111:yolo-testing'],
        ]]),
    ], $snsCaptured);

    $alarm = new TypesenseAlarm(
        suffix: 'quorum-lost',
        description: 'Typesense quorum lost',
        namespace: 'AWS/ApplicationELB',
        metricName: 'HealthyHostCount',
        dimensions: [['Name' => 'TargetGroup', 'Value' => 'targetgroup/yolo-testing-search/abc']],
        statistic: 'Minimum',
        comparisonOperator: 'LessThanThreshold',
        threshold: 2,
        evaluationPeriods: 1,
    );

    expect($alarm->name())->toBe('yolo-testing-typesense-quorum-lost')
        ->and($alarm->exists())->toBeTrue();

    $changes = $alarm->synchroniseConfiguration(apply: true);
    expect($changes)->toHaveCount(1);

    $put = collect($captured)->firstWhere('name', 'PutMetricAlarm');
    expect($put['args']['Threshold'])->toBe(2.0)
        ->and($put['args']['AlarmActions'])->toBe(['arn:aws:sns:ap-southeast-2:111111111111:yolo-testing'])
        ->and($put['args']['TreatMissingData'])->toBe('breaching');

    $alarm->delete();
    expect(collect($captured)->firstWhere('name', 'DeleteAlarms')['args']['AlarmNames'])->toBe(['yolo-testing-typesense-quorum-lost']);
});

it('the search target group creates on 8108 with /health checks and a short drain', function (): void {
    $ec2Captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-123']]]),
    ], $ec2Captured);

    $captured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [[
            'TargetGroupName' => 'yolo-testing-search',
            'TargetGroupArn' => 'arn:tg',
        ]]]),
        'CreateTargetGroup' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:tg']]]),
    ], $captured);

    $group = new SearchTargetGroup();
    $group->create();

    $create = collect($captured)->firstWhere('name', 'CreateTargetGroup');
    expect($create['args']['Port'])->toBe(8108)
        ->and($create['args']['HealthCheckPath'])->toBe('/health')
        ->and($create['args']['TargetType'])->toBe('ip');

    $attributes = collect($captured)->firstWhere('name', 'ModifyTargetGroupAttributes');
    expect($attributes['args']['Attributes'][0]['Value'])->toBe('10');

    $group->delete();
    expect(array_column($captured, 'name'))->toContain('DeleteTargetGroup');
});

it('the search listener rule forwards the search host to the search target group by stable Name identity', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['manifest' => "domain: example.com.au\nservices: {  }\n"], $captured);

    $elbCaptured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupName' => 'yolo-testing-search', 'TargetGroupArn' => 'arn:tg']]]),
        'DescribeRules' => new Result(['Rules' => []]),
        'DescribeTags' => new Result(['TagDescriptions' => []]),
    ], $elbCaptured);

    $rule = new SearchListenerRule('arn:listener');

    expect($rule->name())->toBe('yolo-testing-search')
        ->and($rule->scope()->value)->toBe('env')
        ->and($rule->hosts())->toBe(['search.example.com.au'])
        ->and($rule->exists())->toBeFalse();
});

it('the private DNS namespace waits its async create out and cascades its services on delete', function (): void {
    $captured = [];
    bindTypesenseRoutedClient('serviceDiscovery', ServiceDiscoveryClient::class, [
        'ListNamespaces' => new Result(['Namespaces' => [[
            'Id' => 'ns-123',
            'Arn' => 'arn:ns',
            'Name' => 'testing.internal',
        ]]]),
        'ListServices' => new Result(['Services' => [
            ['Id' => 'srv-0', 'Name' => 'typesense-0', 'Arn' => 'arn:srv0'],
        ]]),
        'DeleteNamespace' => new Result(['OperationId' => 'op-1']),
        'GetOperation' => new Result(['Operation' => ['Status' => 'SUCCESS']]),
    ], $captured);

    $namespace = new PrivateDnsNamespace();

    expect($namespace->name())->toBe('testing.internal')
        ->and($namespace->exists())->toBeTrue()
        ->and($namespace->id())->toBe('ns-123');

    $namespace->delete();

    $names = array_column($captured, 'name');
    expect($names)->toContain('DeleteService')->toContain('DeleteNamespace')->toContain('GetOperation');
    expect(array_search('DeleteService', $names))->toBeLessThan(array_search('DeleteNamespace', $names));
});

it('a discovery service registers an A record with a 10s TTL under the namespace', function (): void {
    $captured = [];
    bindTypesenseRoutedClient('serviceDiscovery', ServiceDiscoveryClient::class, [
        'ListNamespaces' => new Result(['Namespaces' => [['Id' => 'ns-123', 'Arn' => 'arn:ns', 'Name' => 'testing.internal']]]),
        'ListServices' => new Result(['Services' => []]),
        'CreateService' => new Result(['Service' => ['Id' => 'srv-1']]),
    ], $captured);

    $service = new TypesenseDiscoveryService(1);

    expect($service->name())->toBe('typesense-1')
        ->and($service->exists())->toBeFalse();

    $service->create();

    $create = collect($captured)->firstWhere('name', 'CreateService');
    expect($create['args']['NamespaceId'])->toBe('ns-123')
        ->and($create['args']['DnsConfig']['DnsRecords'][0])->toBe(['Type' => 'A', 'TTL' => 10])
        ->and($create['args']['HealthCheckCustomConfig'])->toBe(['FailureThreshold' => 1]);
});

it('a failed service-discovery operation throws rather than resolving a half-made namespace', function (): void {
    $captured = [];
    bindTypesenseRoutedClient('serviceDiscovery', ServiceDiscoveryClient::class, [
        'GetOperation' => new Result(['Operation' => ['Status' => 'FAIL', 'ErrorMessage' => 'vpc gone']]),
    ], $captured);

    expect(fn () => ServiceDiscoveryApi::waitForOperation('op-9'))
        ->toThrow(RuntimeException::class, 'vpc gone');
});

it('a node service is named, scoped and cluster-bound per node', function (): void {
    $service = new TypesenseService(2);

    expect($service->name())->toBe('yolo-testing-typesense-2')
        ->and($service->scope()->value)->toBe('env')
        ->and($service->taskDefinitionFamily())->toBe('yolo-testing-typesense');
});

it('the search record set resources resolve nothing on a greenfield zone', function (): void {
    $captured = [];
    bindTypesenseRoutedClient('route53', Route53Client::class, [
        'ListHostedZones' => new Result(['HostedZones' => []]),
    ], $captured);

    bindServiceLifecycleWorld(['manifest' => "domain: example.com.au\nservices: {  }\n"], $captured);

    expect(fn (): array => Route53::hostedZone('example.com.au'))
        ->toThrow(ResourceDoesNotExistException::class);
});
