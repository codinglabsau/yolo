<?php

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Steps\Sync\App\SyncCloudWatchDashboardStep;

/**
 * A resolved dashboard context — the struct body() is a pure function of, so the
 * whole document can be asserted without touching AWS. Defaults describe a solo
 * web app with an Aurora cluster and an asset distribution; tests override.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dashboardContext(array $overrides = []): array
{
    return array_merge([
        'region' => 'ap-southeast-2',
        'web' => true,
        'clusterName' => 'yolo-testing-my-app',
        'serviceName' => 'yolo-testing-my-app-web',
        'albSuffix' => 'app/yolo-testing/abc123def456',
        'targetGroupSuffix' => 'targetgroup/yolo-testing-my-app/0a1b2c3d4e5f',
        'distributionId' => 'E123ABCDEF',
        'queuePrefix' => 'yolo-testing-my-app-',
        'queues' => ['yolo-testing-my-app'],
        'rds' => ['identifier' => 'my-cluster', 'cluster' => true],
        'buckets' => ['yolo-testing-my-app-artefacts', 'yolo-testing-my-app-assets'],
        'taskLogGroup' => '/yolo/testing-my-app',
        'ivsLogGroup' => null,
        'depthThreshold' => 100,
    ], $overrides);
}

/** @param  array{widgets: array<int, array<string, mixed>>}  $body */
function widgetTitles(array $body): array
{
    return collect($body['widgets'])
        ->map(fn (array $widget) => $widget['properties']['title'] ?? $widget['properties']['markdown'] ?? null)
        ->filter()
        ->values()
        ->all();
}

/** @param  array{widgets: array<int, array<string, mixed>>}  $body */
function findWidget(array $body, string $title): ?array
{
    return collect($body['widgets'])->first(fn (array $widget) => ($widget['properties']['title'] ?? null) === $title);
}

/** Bind an S3 client whose GetObject always returns the given env body. */
function bindDashboardEnv(string $body): void
{
    $result = new Result(['Body' => $body]);

    $mock = new class($result) extends MockHandler
    {
        public function __construct(protected Result $result) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor($this->result);
        }
    };

    Helpers::app()->instance('s3', new S3Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

beforeEach(function () {
    writeManifest(['region' => 'ap-southeast-2', 'account-id' => '111111111111']);
});

it('names the dashboard per app + environment', function () {
    expect((new Dashboard())->name())->toBe('yolo-testing-my-app-dashboard');
});

it('maps an Aurora endpoint to a cluster target, an instance endpoint to an instance target', function () {
    expect(Dashboard::rdsTarget('my-cluster.cluster-cabc123.ap-southeast-2.rds.amazonaws.com'))
        ->toBe(['identifier' => 'my-cluster', 'cluster' => true]);

    expect(Dashboard::rdsTarget('my-db.cabc123.ap-southeast-2.rds.amazonaws.com'))
        ->toBe(['identifier' => 'my-db', 'cluster' => false]);
});

it('returns no RDS target for a non-RDS, proxy or absent host', function () {
    expect(Dashboard::rdsTarget(null))->toBeNull();
    expect(Dashboard::rdsTarget('127.0.0.1'))->toBeNull();
    expect(Dashboard::rdsTarget('db.internal.example.com'))->toBeNull();
    expect(Dashboard::rdsTarget('my-proxy.proxy-cabc.ap-southeast-2.rds.amazonaws.com'))->toBeNull();
});

it('parses the CloudWatch dimension suffix out of ELB ARNs', function () {
    expect(Dashboard::loadBalancerDimension('arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123'))
        ->toBe('app/yolo-testing/abc123');

    expect(Dashboard::targetGroupDimension('arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:targetgroup/yolo-testing-my-app/def456'))
        ->toBe('targetgroup/yolo-testing-my-app/def456');
});

it('builds every section for a full web app', function () {
    $body = Dashboard::body(dashboardContext());

    expect(widgetTitles($body))->toContain('# Web', '# Queue', '# Database', '# CDN & storage', '# Logs');

    expect(findWidget($body, 'CPU utilisation')['properties']['metrics'][0])
        ->toContain('AWS/ECS', 'yolo-testing-my-app', 'yolo-testing-my-app-web');

    expect(findWidget($body, 'Tasks (running vs desired)')['properties']['metrics'][0])
        ->toContain('ECS/ContainerInsights', 'RunningTaskCount');

    expect(findWidget($body, 'RDS CPU')['properties']['metrics'][0])
        ->toContain('AWS/RDS', 'DBClusterIdentifier', 'my-cluster', 'Role', 'WRITER');
});

it('charts ALB target health off both the target-group and load-balancer dimensions', function () {
    $health = findWidget(Dashboard::body(dashboardContext()), 'Target health');

    expect($health['properties']['metrics'][0])
        ->toContain('AWS/ApplicationELB', 'HealthyHostCount', 'TargetGroup', 'targetgroup/yolo-testing-my-app/0a1b2c3d4e5f', 'LoadBalancer', 'app/yolo-testing/abc123def456');

    // Healthy reads the conservative floor (Minimum) over the period.
    expect(end($health['properties']['metrics'][0])['stat'])->toBe('Minimum');
    expect($health['properties']['annotations']['horizontal'][0]['value'])->toBe(1);
});

it('omits the target-health panel when the target group is not resolved yet', function () {
    $body = Dashboard::body(dashboardContext(['targetGroupSuffix' => null]));

    expect(findWidget($body, 'Target health'))->toBeNull();
    // The 5xx rate only needs the ALB, so it still renders and takes the left slot.
    expect(findWidget($body, '5xx error rate')['x'])->toBe(0);
});

it('expresses the 5xx error rate as a percentage of requests with a 1% SLO line', function () {
    $rate = findWidget(Dashboard::body(dashboardContext()), '5xx error rate');

    expect($rate['properties']['metrics'][0][0]['expression'])->toBe('(m1 + m2) / m3 * 100');
    expect($rate['properties']['metrics'])->toHaveCount(4); // expression + target 5xx + elb 5xx + requests
    expect($rate['properties']['annotations']['horizontal'][0]['value'])->toBe(1);
    expect($rate['x'])->toBe(12); // sits beside target health when the target group exists
});

it('charts RDS read and write latency with p90 alongside the average', function () {
    $latency = findWidget(Dashboard::body(dashboardContext()), 'RDS read/write latency');

    $metrics = collect($latency['properties']['metrics']);

    expect($metrics)->toHaveCount(4);
    expect($metrics->map(fn ($m) => $m[1])->all())->toBe(['ReadLatency', 'ReadLatency', 'WriteLatency', 'WriteLatency']);
    expect($metrics->filter(fn ($m) => (end($m)['stat'] ?? null) === 'p90'))->toHaveCount(2);
    expect($latency['properties']['metrics'][0])->toContain('AWS/RDS', 'DBClusterIdentifier', 'my-cluster', 'Role', 'WRITER');
});

it('charts the CloudFront cache hit rate, and omits it with the rest of the CDN panels until the distribution exists', function () {
    $hitRate = findWidget(Dashboard::body(dashboardContext()), 'Asset CDN — cache hit rate');

    expect($hitRate['properties']['region'])->toBe('us-east-1');
    expect($hitRate['properties']['metrics'][0])->toContain('AWS/CloudFront', 'CacheHitRate', 'Global', 'E123ABCDEF');

    expect(findWidget(Dashboard::body(dashboardContext(['distributionId' => null])), 'Asset CDN — cache hit rate'))->toBeNull();
});

it('queries CloudFront in us-east-1 with the Global region dimension', function () {
    $requests = findWidget(Dashboard::body(dashboardContext()), 'Asset CDN — requests');

    expect($requests['properties']['region'])->toBe('us-east-1');
    expect($requests['properties']['metrics'][0])->toContain('AWS/CloudFront', 'Global', 'E123ABCDEF');
});

it('annotates queue depth with the same threshold the alarm uses', function () {
    $depth = findWidget(Dashboard::body(dashboardContext(['depthThreshold' => 250])), 'Queue depth');

    expect($depth['properties']['annotations']['horizontal'][0]['value'])->toBe(250);
    expect($depth['properties']['metrics'][0])->toContain('AWS/SQS', 'ApproximateNumberOfMessagesVisible');
});

it('renders one queue series per tenant plus the landlord', function () {
    $body = Dashboard::body(dashboardContext([
        'queues' => ['yolo-testing-my-app-landlord', 'yolo-testing-my-app-acme', 'yolo-testing-my-app-globex'],
    ]));

    $depth = findWidget($body, 'Queue depth');

    expect($depth['properties']['metrics'])->toHaveCount(3);
    expect(collect($depth['properties']['metrics'])->map(fn ($m) => end($m)['label'])->all())
        ->toBe(['landlord', 'acme', 'globex']);
});

it('omits the CloudFront panels until the distribution exists', function () {
    $body = Dashboard::body(dashboardContext(['distributionId' => null]));

    expect(findWidget($body, 'Asset CDN — requests'))->toBeNull();
    expect(findWidget($body, 'S3 storage size'))->not->toBeNull();
});

it('drops the web, database and log sections for a headless app with no env DB', function () {
    $body = Dashboard::body(dashboardContext([
        'web' => false,
        'clusterName' => null,
        'serviceName' => null,
        'albSuffix' => null,
        'targetGroupSuffix' => null,
        'distributionId' => null,
        'rds' => null,
        'taskLogGroup' => null,
    ]));

    expect(widgetTitles($body))->toContain('# Queue', '# CDN & storage');
    expect(widgetTitles($body))->not->toContain('# Web', '# Database', '# Logs');
});

it('adds an IVS logs panel when IVS logging is enabled', function () {
    $ivs = findWidget(Dashboard::body(dashboardContext(['ivsLogGroup' => '/aws/ivs/testing-my-app'])), 'IVS logs');

    expect($ivs['type'])->toBe('log');
    expect($ivs['properties']['query'])->toContain("SOURCE '/aws/ivs/testing-my-app'");
});

it('creates the dashboard when it does not exist (apply) and reports WOULD_CREATE on a dry-run', function () {
    bindDashboardEnv("APP_ENV=production\n");

    $captured = [];
    bindMockCloudWatchClient(['GetDashboard' => new AwsException('not found', new Command('GetDashboard'))], $captured);

    expect((new SyncCloudWatchDashboardStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(collect($captured)->pluck('name'))->not->toContain('PutDashboard');

    $captured = [];
    bindMockCloudWatchClient(['GetDashboard' => new AwsException('not found', new Command('GetDashboard'))], $captured);

    expect((new SyncCloudWatchDashboardStep())(['dry-run' => false]))->toBe(StepResult::CREATED);
    expect(collect($captured)->pluck('name'))->toContain('PutDashboard');
});

it('makes no write when the live body already matches', function () {
    bindDashboardEnv("APP_ENV=production\n");

    $desired = Dashboard::body((new Dashboard())->resolveContext());

    $captured = [];
    bindMockCloudWatchClient([
        'GetDashboard' => new Result(['DashboardBody' => json_encode($desired)]),
    ], $captured);

    expect((new SyncCloudWatchDashboardStep())(['dry-run' => false]))->toBe(StepResult::SYNCED);
    expect(collect($captured)->pluck('name'))->not->toContain('PutDashboard');
});

it('rewrites a drifted dashboard on apply and only reports it on a dry-run', function () {
    bindDashboardEnv("APP_ENV=production\n");

    $captured = [];
    bindMockCloudWatchClient(['GetDashboard' => new Result(['DashboardBody' => json_encode(['widgets' => []])])], $captured);

    expect((new SyncCloudWatchDashboardStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect(collect($captured)->pluck('name'))->not->toContain('PutDashboard');

    $captured = [];
    bindMockCloudWatchClient(['GetDashboard' => new Result(['DashboardBody' => json_encode(['widgets' => []])])], $captured);

    expect((new SyncCloudWatchDashboardStep())(['dry-run' => false]))->toBe(StepResult::SYNCED);
    expect(collect($captured)->pluck('name'))->toContain('PutDashboard');
});

it('builds a console deep link from its name and the env region', function () {
    writeManifest(['region' => 'ap-southeast-2']);

    expect((new Dashboard())->consoleUrl())
        ->toBe('https://ap-southeast-2.console.aws.amazon.com/cloudwatch/home?region=ap-southeast-2#dashboards/dashboard/yolo-testing-my-app-dashboard');
});

it('returns no console link when the env declares no region', function () {
    writeManifest([]);

    expect((new Dashboard())->consoleUrl())->toBeNull();
});
