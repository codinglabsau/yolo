<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\Sns\SnsClient;
use Aws\CommandInterface;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Aws\CloudWatch\CloudWatchClient;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Steps\Sync\App\SyncWebAlertAlarmStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncAlertAlarmsStep;
use Codinglabs\Yolo\Steps\Destroy\App\TeardownWebAlertAlarmStep;
use Codinglabs\Yolo\Steps\Destroy\Environment\TeardownAlertAlarmsStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    // Rds::target() memoises classification per identifier for the process —
    // flush so each test's mocked RDS world classifies fresh.
    Rds::flushTargets();
});

function bindAlertsSnsClient(): void
{
    $mock = new class() extends MockHandler
    {
        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor(new Result(['Topics' => [
                ['TopicArn' => 'arn:aws:sns:ap-southeast-2:111111111111:yolo-testing-alarms'],
            ]]));
        }
    };

    Helpers::app()->instance('sns', new SnsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

function alertsLoadBalancersResult(): Result
{
    return new Result(['LoadBalancers' => [[
        'LoadBalancerName' => 'yolo-testing',
        'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-testing/abc123',
    ]]]);
}

function alertsAuroraResults(string $writerClass = 'db.r6g.large'): array
{
    return [
        'DescribeDBClusters' => new Result(['DBClusters' => [[
            'DBClusterIdentifier' => 'testing-cluster',
            'DBClusterMembers' => [
                ['DBInstanceIdentifier' => 'testing-writer', 'IsClusterWriter' => true],
                ['DBInstanceIdentifier' => 'testing-reader', 'IsClusterWriter' => false],
            ],
        ]]]),
        'DescribeDBInstances' => new Result(['DBInstances' => [
            ['DBInstanceIdentifier' => 'testing-writer', 'DBInstanceClass' => $writerClass, 'Endpoint' => ['Address' => 'w.example.com']],
            ['DBInstanceIdentifier' => 'testing-reader', 'DBInstanceClass' => $writerClass, 'Endpoint' => ['Address' => 'r.example.com']],
        ]]),
    ];
}

it('provisions the full env alert family against the writer class capacity', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'database' => 'testing-cluster',
    ]);

    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client(['DescribeLoadBalancers' => alertsLoadBalancersResult()], $elb);
    $rds = [];
    bindMockRdsClient(alertsAuroraResults(), $rds);

    expect((new SyncAlertAlarmsStep())([]))->toBe(StepResult::CREATED);

    $puts = collect($cloudWatch)->where('name', 'PutMetricAlarm')->pluck('args')->keyBy('AlarmName');

    expect($puts->keys()->all())->toEqualCanonicalizing([
        'yolo-testing-alert-alb-5xx',
        'yolo-testing-alert-valkey-memory',
        'yolo-testing-alert-valkey-evictions',
        'yolo-testing-alert-database-cpu',
        'yolo-testing-alert-database-memory',
        'yolo-testing-alert-database-connections',
        'yolo-testing-alert-database-buffer-cache',
    ]);

    expect($puts['yolo-testing-alert-alb-5xx']['Threshold'])->toBe(25.0)
        ->and($puts['yolo-testing-alert-alb-5xx']['Statistic'])->toBe('Sum')
        ->and($puts['yolo-testing-alert-alb-5xx']['Dimensions'])->toBe([
            ['Name' => 'LoadBalancer', 'Value' => 'app/yolo-testing/abc123'],
        ]);

    expect($puts['yolo-testing-alert-valkey-memory']['Dimensions'])->toBe([
        ['Name' => 'CacheClusterId', 'Value' => 'yolo-testing-cache-001'],
    ]);

    // r6g.large: 16 GiB → 5% floor in bytes; 1000 default connections → 75%.
    expect($puts['yolo-testing-alert-database-cpu']['Threshold'])->toBe(80.0)
        ->and($puts['yolo-testing-alert-database-memory']['Threshold'])->toBe(round(16 * 0.05 * 1024 ** 3))
        ->and($puts['yolo-testing-alert-database-connections']['Threshold'])->toBe(750.0)
        ->and($puts['yolo-testing-alert-valkey-evictions']['Threshold'])->toBe(100.0)
        ->and($puts['yolo-testing-alert-database-buffer-cache']['ComparisonOperator'])->toBe('LessThanThreshold')
        ->and($puts['yolo-testing-alert-database-buffer-cache']['DatapointsToAlarm'])->toBe(6);

    expect($puts['yolo-testing-alert-database-cpu']['Dimensions'])->toEqualCanonicalizing([
        ['Name' => 'DBClusterIdentifier', 'Value' => 'testing-cluster'],
        ['Name' => 'Role', 'Value' => 'WRITER'],
    ]);

    foreach ($puts as $payload) {
        expect($payload['AlarmActions'])->toBe(['arn:aws:sns:ap-southeast-2:111111111111:yolo-testing-alarms'])
            ->and($payload['TreatMissingData'])->toBe('notBreaching');
    }
});

it('skips the database set when the manifest declares no database', function (): void {
    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client(['DescribeLoadBalancers' => alertsLoadBalancersResult()], $elb);

    (new SyncAlertAlarmsStep())([]);

    $names = collect($cloudWatch)->where('name', 'PutMetricAlarm')->pluck('args.AlarmName');

    expect($names->filter(fn (string $name): bool => str_contains($name, 'database')))->toBeEmpty()
        ->and($names)->toHaveCount(3);
});

it('hard-fails on a database class missing from the capacity table', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'database' => 'testing-cluster',
    ]);

    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client(['DescribeLoadBalancers' => alertsLoadBalancersResult()], $elb);
    $rds = [];
    bindMockRdsClient(alertsAuroraResults(writerClass: 'db.x2g.16xlarge'), $rds);

    (new SyncAlertAlarmsStep())([]);
})->throws(IntegrityCheckException::class, 'db.x2g.16xlarge');

it('reports the alb alarm pending while the load balancer is unresolvable', function (): void {
    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client(['DescribeLoadBalancers' => new Result(['LoadBalancers' => []])], $elb);

    $step = new SyncAlertAlarmsStep();
    $step([]);

    $names = collect($cloudWatch)->where('name', 'PutMetricAlarm')->pluck('args.AlarmName');

    expect($names)->not->toContain('yolo-testing-alert-alb-5xx')
        ->and(collect($step->changes())->pluck('attribute'))->toContain('alb 5xx alert');
});

it('provisions the app web 5xx rate alarm with a traffic floor', function (): void {
    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => alertsLoadBalancersResult(),
        'DescribeTargetGroups' => new Result(['TargetGroups' => [[
            'TargetGroupName' => 'yolo-testing-my-app',
            'TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111:targetgroup/yolo-testing-my-app/xyz789',
        ]]]),
    ], $elb);

    expect((new SyncWebAlertAlarmStep())([]))->toBe(StepResult::CREATED);

    $put = collect($cloudWatch)->firstWhere('name', 'PutMetricAlarm')['args'];

    expect($put['AlarmName'])->toBe('yolo-testing-my-app-alert-web-5xx')
        ->and($put['Threshold'])->toBe(5.0)
        ->and($put['EvaluationPeriods'])->toBe(5)
        ->and($put['DatapointsToAlarm'])->toBe(3)
        ->and($put)->not->toHaveKey('Namespace');

    $metrics = collect($put['Metrics'])->keyBy('Id');

    expect($metrics['rate']['Expression'])->toBe('IF(requests >= 60, 100 * FILL(errors, 0) / requests, 0)')
        ->and($metrics['requests']['MetricStat']['Metric']['Dimensions'])->toEqualCanonicalizing([
            ['Name' => 'TargetGroup', 'Value' => 'targetgroup/yolo-testing-my-app/xyz789'],
            ['Name' => 'LoadBalancer', 'Value' => 'app/yolo-testing/abc123'],
        ])
        ->and($metrics['errors']['MetricStat']['Metric']['MetricName'])->toBe('HTTPCode_Target_5XX_Count');
});

it('reports the web alarm pending while the target group is unresolvable', function (): void {
    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => alertsLoadBalancersResult(),
        'DescribeTargetGroups' => new Result(['TargetGroups' => []]),
    ], $elb);

    $step = new SyncWebAlertAlarmStep();

    expect($step([]))->toBe(StepResult::WOULD_CREATE);
    expect(collect($cloudWatch)->where('name', 'PutMetricAlarm'))->toBeEmpty();
    expect(collect($step->changes())->pluck('attribute'))->toContain('web 5xx alert');
});

/**
 * Bind a CloudWatch client over a map of live alarms (AlarmName => record):
 * DescribeAlarms honours the AlarmNames filter, ListTagsForResource answers
 * per-ARN with the tags the adoption guard expects, writes capture and no-op.
 *
 * @param  array<string, array<string, mixed>>  $liveAlarms
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindAlertsCloudWatchWorld(array $liveAlarms, array &$captured): void
{
    $mock = new class($liveAlarms, $captured) extends MockHandler
    {
        public function __construct(protected array $liveAlarms, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $args = $cmd->toArray();
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $args];

            return Create::promiseFor(match ($cmd->getName()) {
                'DescribeAlarms' => new Result(['MetricAlarms' => array_values(array_intersect_key(
                    $this->liveAlarms,
                    isset($args['AlarmNames']) ? array_flip($args['AlarmNames']) : $this->liveAlarms,
                ))]),
                'ListTagsForResource' => new Result(['Tags' => alertsTagsForArn((string) $args['ResourceARN'])]),
                default => new Result([]),
            });
        }
    };

    Helpers::app()->instance('cloudWatch', new CloudWatchClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/**
 * @return array<int, array{Key: string, Value: string}>
 */
function alertsTagsForArn(string $arn): array
{
    $name = substr($arn, strrpos($arn, ':') + 1);
    $app = str_contains($name, '-my-app-');

    return collect(array_filter([
        'Name' => $name,
        'yolo:scope' => $app ? 'app' : 'env',
        'yolo:environment' => 'testing',
        'yolo:app' => $app ? 'my-app' : null,
    ]))->map(fn (string $value, string $key): array => ['Key' => $key, 'Value' => $value])->values()->all();
}

/**
 * The live-alarm map an in-sync world holds, built by running the create path
 * and echoing its own PutMetricAlarm payloads back — in sync by construction.
 *
 * @return array<string, array<string, mixed>>
 */
function alertsProvisionedAlarms(): array
{
    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => alertsLoadBalancersResult(),
        'DescribeTargetGroups' => new Result(['TargetGroups' => [[
            'TargetGroupName' => 'yolo-testing-my-app',
            'TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111:targetgroup/yolo-testing-my-app/xyz789',
        ]]]),
    ], $elb);
    $rds = [];
    bindMockRdsClient(alertsAuroraResults(), $rds);

    (new SyncAlertAlarmsStep())([]);
    (new SyncWebAlertAlarmStep())([]);

    return collect($cloudWatch)
        ->where('name', 'PutMetricAlarm')
        ->pluck('args')
        ->keyBy('AlarmName')
        ->map(fn (array $payload): array => [
            ...$payload,
            'AlarmArn' => 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:' . $payload['AlarmName'],
        ])
        ->all();
}

it('skips the database set for a plain-instance database', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'database' => 'testing-instance',
    ]);

    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client(['DescribeLoadBalancers' => alertsLoadBalancersResult()], $elb);
    $rds = [];
    bindMockRdsClient([
        'DescribeDBClusters' => new Result(['DBClusters' => []]),
        'DescribeDBInstances' => new Result(['DBInstances' => [
            ['DBInstanceIdentifier' => 'testing-instance', 'DBInstanceClass' => 'db.t4g.medium', 'Endpoint' => ['Address' => 'i.example.com']],
        ]]),
    ], $rds);

    $step = new SyncAlertAlarmsStep();
    $step([]);

    $names = collect($cloudWatch)->where('name', 'PutMetricAlarm')->pluck('args.AlarmName');

    expect($names->filter(fn (string $name): bool => str_contains($name, 'database')))->toBeEmpty()
        ->and($names)->toHaveCount(3);
});

it('keeps the percentage pair only for a Serverless v2 writer', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'database' => 'testing-cluster',
    ]);

    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client(['DescribeLoadBalancers' => alertsLoadBalancersResult()], $elb);
    $rds = [];
    bindMockRdsClient(alertsAuroraResults(writerClass: 'db.serverless'), $rds);

    (new SyncAlertAlarmsStep())([]);

    $names = collect($cloudWatch)->where('name', 'PutMetricAlarm')->pluck('args.AlarmName');

    expect($names)->toContain('yolo-testing-alert-database-cpu')
        ->toContain('yolo-testing-alert-database-buffer-cache')
        ->not->toContain('yolo-testing-alert-database-memory')
        ->not->toContain('yolo-testing-alert-database-connections');
});

it('reports the database alerts pending while the cluster has no resolvable writer', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'database' => 'testing-cluster',
    ]);

    bindAlertsSnsClient();
    $cloudWatch = [];
    bindMockCloudWatchClient([], $cloudWatch);
    $elb = [];
    bindRoutedElbV2Client(['DescribeLoadBalancers' => alertsLoadBalancersResult()], $elb);
    $rds = [];
    bindMockRdsClient([
        'DescribeDBClusters' => new Result(['DBClusters' => [[
            'DBClusterIdentifier' => 'testing-cluster',
            'DBClusterMembers' => [
                ['DBInstanceIdentifier' => 'testing-reader', 'IsClusterWriter' => false],
            ],
        ]]]),
        'DescribeDBInstances' => new Result(['DBInstances' => []]),
    ], $rds);

    $step = new SyncAlertAlarmsStep();
    $step([]);

    $names = collect($cloudWatch)->where('name', 'PutMetricAlarm')->pluck('args.AlarmName');

    expect($names->filter(fn (string $name): bool => str_contains($name, 'database')))->toBeEmpty()
        ->and(collect($step->changes())->pluck('attribute'))->toContain('database alerts');
});

it('honours the reconciler contract for the env alert family', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'database' => 'testing-cluster',
    ]);

    $live = alertsProvisionedAlarms();

    $bindWorld = function (array &$captured) use ($live): void {
        Rds::flushTargets();
        bindAlertsSnsClient();
        bindAlertsCloudWatchWorld($live, $captured);
        $elb = [];
        bindRoutedElbV2Client(['DescribeLoadBalancers' => alertsLoadBalancersResult()], $elb);
        $rds = [];
        bindMockRdsClient(alertsAuroraResults(), $rds);
    };

    assertSyncStepReconciles(
        makeStep: fn (): SyncAlertAlarmsStep => new SyncAlertAlarmsStep(),
        bindInSync: $bindWorld,
        bindDrifted: function (array &$captured) use ($live, $bindWorld): void {
            $live['yolo-testing-alert-alb-5xx']['Threshold'] = 999;
            $bindWorld($captured);
            bindAlertsCloudWatchWorld($live, $captured);
        },
        writeCommand: 'PutMetricAlarm',
    );
});

it('honours the reconciler contract for the app web 5xx alarm, including metric-math drift', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'database' => 'testing-cluster',
    ]);

    $live = alertsProvisionedAlarms();

    $bindWorld = function (array &$captured) use ($live): void {
        bindAlertsSnsClient();
        bindAlertsCloudWatchWorld($live, $captured);
        $elb = [];
        bindRoutedElbV2Client([
            'DescribeLoadBalancers' => alertsLoadBalancersResult(),
            'DescribeTargetGroups' => new Result(['TargetGroups' => [[
                'TargetGroupName' => 'yolo-testing-my-app',
                'TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111:targetgroup/yolo-testing-my-app/xyz789',
            ]]]),
        ], $elb);
    };

    assertSyncStepReconciles(
        makeStep: fn (): SyncWebAlertAlarmStep => new SyncWebAlertAlarmStep(),
        bindInSync: $bindWorld,
        bindDrifted: function (array &$captured) use ($live, $bindWorld): void {
            // The semantics live in the metric math — a drifted expression
            // (e.g. a hand-lowered traffic floor) must reconcile back.
            $live['yolo-testing-my-app-alert-web-5xx']['Metrics'][2]['Expression'] = 'IF(requests >= 1, 100 * FILL(errors, 0) / requests, 0)';
            $bindWorld($captured);
            bindAlertsCloudWatchWorld($live, $captured);
        },
        writeCommand: 'PutMetricAlarm',
    );
});

it('tears down the app web alarm by the exact name the sync step created', function (): void {
    $cloudWatch = [];
    bindAlertsCloudWatchWorld([
        'yolo-testing-my-app-alert-web-5xx' => [
            'AlarmName' => 'yolo-testing-my-app-alert-web-5xx',
            'AlarmArn' => 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:yolo-testing-my-app-alert-web-5xx',
        ],
    ], $cloudWatch);

    expect((new TeardownWebAlertAlarmStep())([]))->toBe(StepResult::DELETED);

    expect(collect($cloudWatch)->firstWhere('name', 'DeleteAlarms')['args']['AlarmNames'])
        ->toBe(['yolo-testing-my-app-alert-web-5xx']);
});

it('tears down the env alert family by name without touching the sources they watched', function (): void {
    $existing = collect([
        'yolo-testing-alert-alb-5xx',
        'yolo-testing-alert-database-cpu',
    ])->map(fn (string $name): array => ['AlarmName' => $name, 'AlarmArn' => 'arn:' . $name])->all();

    $cloudWatch = [];
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => $existing]),
    ], $cloudWatch);

    expect((new TeardownAlertAlarmsStep())([]))->toBe(StepResult::DELETED);

    $deleted = collect($cloudWatch)->where('name', 'DeleteAlarms')->pluck('args.AlarmNames')->flatten();

    expect($deleted)->toContain('yolo-testing-alert-alb-5xx')
        ->toContain('yolo-testing-alert-database-cpu')
        ->not->toContain('yolo-testing-alert-valkey-memory');
});
