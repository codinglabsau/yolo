<?php

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Services\Alerts;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Steps\Sync\App\SyncCloudWatchDashboardStep;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

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
        'burst' => true,
        'clusterName' => 'yolo-testing-my-app',
        'serviceName' => 'yolo-testing-my-app-web',
        'albSuffix' => 'app/yolo-testing/abc123def456',
        'targetGroupSuffix' => 'targetgroup/yolo-testing-my-app/0a1b2c3d4e5f',
        'distributionId' => 'E123ABCDEF',
        'queuePrefix' => 'yolo-testing-my-app-',
        'queues' => ['yolo-testing-my-app'],
        'queueDisabled' => false,
        // Per-group compute services — null = bundled into web (the default).
        'queueService' => null,
        'schedulerService' => null,
        'rds' => ['identifier' => 'my-cluster', 'cluster' => true],
        'databaseWriterClass' => 'db.r6g.large',
        'cacheNodeId' => 'yolo-testing-cache-001',
        'buckets' => ['yolo-111111111111-testing-my-app-config', 'yolo-111111111111-testing-my-app-assets'],
        'taskLogGroup' => '/yolo/testing-my-app',
        'ivsLogGroup' => null,
        'wafWebAcl' => null,
        'mediaConvertQueueArn' => null,
        'rekognition' => false,
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
    return collect($body['widgets'])->first(fn (array $widget): bool => ($widget['properties']['title'] ?? null) === $title);
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

beforeEach(function (): void {
    writeManifest(['region' => 'ap-southeast-2', 'account-id' => '111111111111']);

    // resolveContext probes the env cache cluster for the # Cache section —
    // an empty ElastiCache world reads as "no cluster yet" (section omitted).
    $elastiCache = [];
    bindMockElastiCacheClient([], $elastiCache);
});

it('names the dashboard per app + environment', function (): void {
    expect((new Dashboard())->name())->toBe('yolo-testing-my-app-dashboard');
});

it('parses the CloudWatch dimension suffix out of ELB ARNs', function (): void {
    expect(Dashboard::loadBalancerDimension('arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123'))
        ->toBe('app/yolo-testing/abc123');

    expect(Dashboard::targetGroupDimension('arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:targetgroup/yolo-testing-my-app/def456'))
        ->toBe('targetgroup/yolo-testing-my-app/def456');
});

it('builds every section for a full web app', function (): void {
    $body = Dashboard::body(dashboardContext());

    expect(widgetTitles($body))->toContain('# Web', '# Queue', '# Database', '# CDN & storage', '# Logs');
});

it('puts the queue section directly below web, ahead of WAF', function (): void {
    $titles = widgetTitles(Dashboard::body(dashboardContext(['wafWebAcl' => 'yolo-testing-waf'])));

    expect(array_search('# Web', $titles, true))->toBeLessThan(array_search('# Queue', $titles, true))
        ->and(array_search('# Queue', $titles, true))->toBeLessThan(array_search('# WAF', $titles, true));
});

it('folds an extracted queue worker compute into the # Queue section; a bundled worker rides web', function (): void {
    // Bundled by default → the # Queue section still renders (its SQS backlog),
    // but charts no compute of its own; the scheduler has no section at all.
    $bundled = Dashboard::body(dashboardContext());
    expect(widgetTitles($bundled))->toContain('# Queue')->not->toContain('# Scheduler');

    // No queue-service compute panel — the only Tasks panel reads web's service.
    expect(collect($bundled['widgets'])
        ->first(fn (array $w): bool => ($w['properties']['title'] ?? null) === 'Tasks (running vs desired)'
            && in_array('yolo-testing-my-app-queue', $w['properties']['metrics'][0], true)))
        ->toBeNull();

    // Extracted → the queue gets its own compute, folded under the SAME # Queue
    // header (web → queue → scheduler order); the scheduler gets its own section.
    $body = Dashboard::body(dashboardContext([
        'queueService' => 'yolo-testing-my-app-queue',
        'schedulerService' => 'yolo-testing-my-app-scheduler',
    ]));

    $titles = widgetTitles($body);
    expect($titles)->toContain('# Web', '# Queue', '# Scheduler')
        ->and(array_search('# Web', $titles, true))->toBeLessThan(array_search('# Queue', $titles, true))
        ->and(array_search('# Queue', $titles, true))->toBeLessThan(array_search('# Scheduler', $titles, true));

    // Exactly one # Queue header — compute and backlog share it.
    expect(collect($titles)->filter(fn (string $title): bool => $title === '# Queue'))->toHaveCount(1);

    // The queue's compute charts read the queue service's own dimensions...
    expect(collect($body['widgets'])
        ->first(fn (array $w): bool => ($w['properties']['title'] ?? null) === 'Tasks (running vs desired)'
            && in_array('yolo-testing-my-app-queue', $w['properties']['metrics'][0], true)))
        ->not->toBeNull();

    // ...and its backlog panels sit in the same section.
    expect(findWidget($body, 'Queue depth'))->not->toBeNull();
});

it('draws the CPU scale line only on web (CPU-scaled), not the backlog-scaled queue', function (): void {
    $body = Dashboard::body(dashboardContext(['queueService' => 'yolo-testing-my-app-queue']));

    $cpuAnnotations = function (string $service) use ($body): array {
        $panel = collect($body['widgets'])->first(fn (array $w): bool => ($w['properties']['title'] ?? null) === 'CPU utilisation'
            && in_array($service, $w['properties']['metrics'][0], true));

        return collect($panel['properties']['annotations']['horizontal'])->pluck('label')->all();
    };

    // Web scales on CPU → both the Scale and Critical lines.
    expect($cpuAnnotations('yolo-testing-my-app-web'))->toContain('Scale', 'Critical');

    // The queue scales on backlog-per-task, not CPU → Critical only, no Scale line.
    expect($cpuAnnotations('yolo-testing-my-app-queue'))->toContain('Critical')->not->toContain('Scale');
});

it('omits the whole queue section when the queue is disabled and bundled (tasks.queue: false)', function (): void {
    $body = Dashboard::body(dashboardContext(['queueDisabled' => true]));

    expect(widgetTitles($body))->not->toContain('# Queue')
        ->and(findWidget($body, 'Queue depth'))->toBeNull()
        ->and(widgetTitles($body))->toContain('# Web', '# Database');   // the rest still render

    expect(findWidget($body, 'CPU utilisation')['properties']['metrics'][0])
        ->toContain('AWS/ECS', 'yolo-testing-my-app', 'yolo-testing-my-app-web');

    expect(findWidget($body, 'Tasks (running vs desired)')['properties']['metrics'][0])
        ->toContain('ECS/ContainerInsights', 'RunningTaskCount');

    expect(findWidget($body, 'RDS CPU')['properties']['metrics'][0])
        ->toContain('AWS/RDS', 'DBClusterIdentifier', 'my-cluster', 'Role', 'WRITER');
});

it('charts ALB target health off both the target-group and load-balancer dimensions', function (): void {
    $health = findWidget(Dashboard::body(dashboardContext()), 'Target health');

    expect($health['properties']['metrics'][0])
        ->toContain('AWS/ApplicationELB', 'HealthyHostCount', 'TargetGroup', 'targetgroup/yolo-testing-my-app/0a1b2c3d4e5f', 'LoadBalancer', 'app/yolo-testing/abc123def456');

    // Healthy reads the conservative floor (Minimum) over the period.
    expect(end($health['properties']['metrics'][0])['stat'])->toBe('Minimum');
    expect($health['properties']['annotations']['horizontal'][0]['value'])->toBe(1);
});

it('omits the target-health panel when the target group is not resolved yet', function (): void {
    $body = Dashboard::body(dashboardContext(['targetGroupSuffix' => null]));

    expect(findWidget($body, 'Target health'))->toBeNull();
    // The 5xx rate only needs the ALB, so it still renders and takes the left slot.
    expect(findWidget($body, '5xx error rate')['x'])->toBe(0);
});

it('draws every alert alarm threshold as a red line from the same values the alarms sync', function (): void {
    $body = Dashboard::body(dashboardContext());

    $line = function (?array $widget): array {
        expect($widget)->not->toBeNull();

        return collect($widget['properties']['annotations']['horizontal'])
            ->firstWhere('color', Dashboard::RED);
    };

    expect($line(findWidget($body, '5xx error rate'))['value'])->toBe(Alerts::WEB_5XX_RATE_PERCENT);
    expect($line(findWidget($body, 'HTTP errors'))['value'])->toBe(Alerts::ALB_5XX_PER_FIVE_MINUTES);
    expect($line(findWidget($body, 'RDS CPU'))['value'])->toBe(Alerts::DATABASE_CPU_PERCENT);
    expect($line(findWidget($body, 'RDS connections'))['value'])->toBe(Alerts::databaseConnectionsCeiling('db.r6g.large'));
    expect($line(findWidget($body, 'RDS freeable memory'))['value'])->toBe(Alerts::databaseMemoryFloorBytes('db.r6g.large'));
    expect($line(findWidget($body, 'Aurora buffer cache hit ratio'))['value'])->toBe(Alerts::DATABASE_BUFFER_CACHE_PERCENT);

    // The HTTP errors panel runs at the alarm's own 5-minute period so the
    // line means what the alarm means.
    expect(findWidget($body, 'HTTP errors')['properties']['period'])->toBe(300);
});

it('renders the # Cache section with the valkey alarm lines, and omits it without a cache node', function (): void {
    $body = Dashboard::body(dashboardContext());

    expect(widgetTitles($body))->toContain('# Cache');
    expect(findWidget($body, 'Valkey memory')['properties']['annotations']['horizontal'][0]['value'])->toBe(Alerts::VALKEY_MEMORY_PERCENT);

    $evictions = findWidget($body, 'Valkey evictions');
    expect($evictions['properties']['annotations']['horizontal'][0]['value'])->toBe(Alerts::VALKEY_EVICTIONS_PER_FIVE_MINUTES)
        ->and($evictions['properties']['period'])->toBe(300);

    expect(widgetTitles(Dashboard::body(dashboardContext(['cacheNodeId' => null]))))->not->toContain('# Cache');
});

it('omits the capacity-derived lines for a Serverless v2 writer but keeps the percentage lines', function (): void {
    $body = Dashboard::body(dashboardContext(['databaseWriterClass' => 'db.serverless']));

    expect(findWidget($body, 'RDS connections')['properties'])->not->toHaveKey('annotations');
    expect(findWidget($body, 'RDS freeable memory')['properties'])->not->toHaveKey('annotations');
    expect(findWidget($body, 'RDS CPU')['properties']['annotations']['horizontal'][0]['value'])->toBe(Alerts::DATABASE_CPU_PERCENT);
});

it('expresses the 5xx error rate as this app target 5xx over its own requests with a 1% SLO line', function (): void {
    $rate = findWidget(Dashboard::body(dashboardContext()), '5xx error rate');

    expect($rate['properties']['metrics'][0][0]['expression'])->toBe('m1 / m2 * 100');
    expect($rate['properties']['metrics'])->toHaveCount(3); // expression + target 5xx + requests
    // Both terms are scoped to this app's target group, not the shared load balancer.
    expect($rate['properties']['metrics'][1])->toContain('HTTPCode_Target_5XX_Count', 'TargetGroup', 'targetgroup/yolo-testing-my-app/0a1b2c3d4e5f');
    expect($rate['properties']['metrics'][2])->toContain('RequestCount', 'TargetGroup', 'targetgroup/yolo-testing-my-app/0a1b2c3d4e5f');
    expect($rate['properties']['annotations']['horizontal'][0]['value'])->toBe(1);
    expect($rate['x'])->toBe(12); // sits beside target health when the target group exists
});

it('scopes the Requests panel to the app target group so a shared ALB does not leak other apps', function (): void {
    $requests = findWidget(Dashboard::body(dashboardContext()), 'Requests');

    // Total requests counts only what the shared ALB forwards to THIS app's target
    // group — not every app behind the load balancer.
    expect($requests['properties']['metrics'][0])
        ->toContain('AWS/ApplicationELB', 'RequestCount', 'TargetGroup', 'targetgroup/yolo-testing-my-app/0a1b2c3d4e5f', 'LoadBalancer', 'app/yolo-testing/abc123def456');

    // Requests per task is already per-target.
    expect($requests['properties']['metrics'][1])
        ->toContain('RequestCountPerTarget', 'TargetGroup', 'targetgroup/yolo-testing-my-app/0a1b2c3d4e5f');
});

it('scopes response time, slow requests and the target HTTP errors to the app target group', function (): void {
    $body = Dashboard::body(dashboardContext());
    $targetGroup = 'targetgroup/yolo-testing-my-app/0a1b2c3d4e5f';

    expect(findWidget($body, 'Response time')['properties']['metrics'][0])->toContain('TargetResponseTime', 'TargetGroup', $targetGroup);
    expect(findWidget($body, 'Slow requests')['properties']['metrics'][0])->toContain('TargetResponseTime', 'TargetGroup', $targetGroup);
    expect(findWidget($body, 'HTTP errors')['properties']['metrics'][0])->toContain('HTTPCode_Target_4XX_Count', 'TargetGroup', $targetGroup);
    expect(findWidget($body, 'HTTP errors')['properties']['metrics'][1])->toContain('HTTPCode_Target_5XX_Count', 'TargetGroup', $targetGroup);

    // ELB-generated 5xx isn't target-group attributable, so it stays load-balancer wide.
    $elb = collect(findWidget($body, 'HTTP errors')['properties']['metrics'])
        ->first(fn (array $metric): bool => $metric[1] === 'HTTPCode_ELB_5XX_Count');
    expect($elb)->toContain('LoadBalancer', 'app/yolo-testing/abc123def456')
        ->and($elb)->not->toContain('TargetGroup');
});

it('labels the response-time ceiling as an SLO reference, not an alarm (no TargetResponseTime alarm exists)', function (): void {
    $labels = collect(findWidget(Dashboard::body(dashboardContext()), 'Response time')['properties']['annotations']['horizontal'])
        ->pluck('label')->all();

    expect($labels)->toContain('SLO', 'Target')->not->toContain('Alarm');
});

it('draws the burst worker-saturation panel with the burst threshold line on an autoscaling web tier', function (): void {
    $panel = findWidget(Dashboard::body(dashboardContext()), 'Worker saturation');

    // No y-axis ceiling: a classic tier reports queued requests past 100.
    expect($panel['properties']['yAxis']['left'])->toBe(['min' => 0]);

    // Reads the same metric the burst alarm reads, dimensioned by this web service.
    expect($panel['properties']['metrics'][0])
        ->toContain(WebBurstPolicy::METRIC_NAMESPACE, WebBurstPolicy::METRIC_NAME, 'yolo-testing-my-app-web')
        ->and(end($panel['properties']['metrics'][0])['stat'])->toBe('Maximum'); // most-saturated task, like the alarm

    $burst = collect($panel['properties']['annotations']['horizontal'])
        ->first(fn (array $annotation): bool => $annotation['label'] === 'Burst');

    expect($burst['value'])->toBe(WebBurstPolicy::ALARM_THRESHOLD);
});

it('omits the worker-saturation panel when the web tier is not autoscaling', function (): void {
    expect(findWidget(Dashboard::body(dashboardContext(['burst' => false])), 'Worker saturation'))->toBeNull();
});

it('falls back to the load-balancer dimension for the front-end panels before the target group resolves', function (): void {
    $requests = findWidget(Dashboard::body(dashboardContext(['targetGroupSuffix' => null])), 'Requests');

    // No target group yet → the bare load balancer is the only signal, and there is
    // no per-task line.
    expect($requests['properties']['metrics'])->toHaveCount(1);
    expect($requests['properties']['metrics'][0])
        ->toContain('RequestCount', 'LoadBalancer', 'app/yolo-testing/abc123def456')
        ->and($requests['properties']['metrics'][0])->not->toContain('TargetGroup');
});

it('charts RDS read and write latency with p90 alongside the average', function (): void {
    $latency = findWidget(Dashboard::body(dashboardContext()), 'RDS read/write latency');

    $metrics = collect($latency['properties']['metrics']);

    expect($metrics)->toHaveCount(4);
    expect($metrics->map(fn ($m): mixed => $m[1])->all())->toBe(['ReadLatency', 'ReadLatency', 'WriteLatency', 'WriteLatency']);
    expect($metrics->filter(fn ($m): bool => (end($m)['stat'] ?? null) === 'p90'))->toHaveCount(2);
    expect($latency['properties']['metrics'][0])->toContain('AWS/RDS', 'DBClusterIdentifier', 'my-cluster', 'Role', 'WRITER');
});

it('charts the CloudFront cache hit rate, and omits it with the rest of the CDN panels until the distribution exists', function (): void {
    $hitRate = findWidget(Dashboard::body(dashboardContext()), 'Asset CDN — cache hit rate');

    expect($hitRate['properties']['region'])->toBe('us-east-1');
    expect($hitRate['properties']['metrics'][0])->toContain('AWS/CloudFront', 'CacheHitRate', 'Global', 'E123ABCDEF');

    expect(findWidget(Dashboard::body(dashboardContext(['distributionId' => null])), 'Asset CDN — cache hit rate'))->toBeNull();
});

it('queries CloudFront in us-east-1 with the Global region dimension', function (): void {
    $requests = findWidget(Dashboard::body(dashboardContext()), 'Asset CDN — requests');

    expect($requests['properties']['region'])->toBe('us-east-1');
    expect($requests['properties']['metrics'][0])->toContain('AWS/CloudFront', 'Global', 'E123ABCDEF');
});

it('graphs the queue depth metric', function (): void {
    $depth = findWidget(Dashboard::body(dashboardContext()), 'Queue depth');

    expect($depth['properties']['metrics'][0])->toContain('AWS/SQS', 'ApproximateNumberOfMessagesVisible');
});

it('renders one queue series per tenant plus the landlord', function (): void {
    $body = Dashboard::body(dashboardContext([
        'queues' => ['yolo-testing-my-app-landlord', 'yolo-testing-my-app-acme', 'yolo-testing-my-app-globex'],
    ]));

    $depth = findWidget($body, 'Queue depth');

    expect($depth['properties']['metrics'])->toHaveCount(3);
    expect(collect($depth['properties']['metrics'])->map(fn ($m): mixed => end($m)['label'])->all())
        ->toBe(['landlord', 'acme', 'globex']);
});

it('omits the CloudFront panels until the distribution exists', function (): void {
    $body = Dashboard::body(dashboardContext(['distributionId' => null]));

    expect(findWidget($body, 'Asset CDN — requests'))->toBeNull();
    expect(findWidget($body, 'S3 storage size'))->not->toBeNull();
});

it('drops the web, database and log sections for a headless app with no env DB', function (): void {
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

it('omits the services section when no chartable service is consumed', function (): void {
    expect(widgetTitles(Dashboard::body(dashboardContext())))->not->toContain('# Services');
});

it('adds a MediaConvert panel when the app consumes the mediaconvert service', function (): void {
    $body = Dashboard::body(dashboardContext([
        'mediaConvertQueueArn' => 'arn:aws:mediaconvert:ap-southeast-2:111111111111:queues/Default',
    ]));

    expect(widgetTitles($body))->toContain('# Services');

    $panel = findWidget($body, 'MediaConvert jobs (account default queue)');

    expect($panel['properties']['metrics'][0])->toBe([
        'AWS/MediaConvert', 'JobsCompletedCount', 'Queue',
        'arn:aws:mediaconvert:ap-southeast-2:111111111111:queues/Default',
        ['label' => 'Completed', 'color' => '#2ca02c'],
    ]);
});

it('adds a Rekognition panel charting every reporting operation', function (): void {
    $body = Dashboard::body(dashboardContext(['rekognition' => true]));

    $panel = findWidget($body, 'Rekognition requests (account, by operation)');

    expect($panel['properties']['metrics'][0][0]['expression'])
        ->toContain('SEARCH')
        ->toContain('SuccessfulRequestCount');
});

it('adds an IVS logs panel when IVS logging is enabled', function (): void {
    $ivs = findWidget(Dashboard::body(dashboardContext(['ivsLogGroup' => '/aws/ivs/testing-my-app'])), 'IVS logs');

    expect($ivs['type'])->toBe('log');
    expect($ivs['properties']['query'])->toContain("SOURCE '/aws/ivs/testing-my-app'");
});

it('omits the WAF panels until the web ACL exists', function (): void {
    expect(widgetTitles(Dashboard::body(dashboardContext())))->not->toContain('# WAF');
});

it('adds WAF panels dimensioned on the env web ACL when it exists', function (): void {
    $body = Dashboard::body(dashboardContext(['wafWebAcl' => 'yolo-testing-waf']));

    expect(widgetTitles($body))->toContain('# WAF');

    $requests = findWidget($body, 'Request disposition');
    $metric = collect($requests['properties']['metrics'])->first(fn (array $m): bool => $m[1] === 'BlockedRequests');

    // [namespace, metric, WebACL, <name>, Region, <region>, Rule, ALL, {options}]
    expect($metric)->toContain('AWS/WAFV2', 'yolo-testing-waf', 'ap-southeast-2', 'ALL');

    $byRule = findWidget($body, 'Blocked by rule');
    expect(collect($byRule['properties']['metrics'])->pluck(7))
        ->toContain('yolo-block-ips', 'yolo-rate-limit', 'AWS-AWSManagedRulesCommonRuleSet');
});

it('charts the Typesense search rate-limit blocks in the WAF group, not # Services', function (): void {
    $body = Dashboard::body(dashboardContext([
        'wafWebAcl' => 'yolo-testing-waf',
        'typesense' => [
            'cluster' => 'yolo-testing-my-app-typesense',
            'services' => ['yolo-testing-my-app-typesense-0'],
            'targetGroupSuffix' => null,
            'albSuffix' => null,
            'logGroup' => '/yolo/testing-my-app-typesense',
        ],
    ]));

    $panel = findWidget($body, 'Search rate-limit blocks');
    expect($panel)->not->toBeNull()
        ->and($panel['properties']['metrics'][0])->toContain('AWS/WAFV2', 'BlockedRequests', 'yolo-testing-waf', 'yolo-search-rate-limit');

    // It sits inside the WAF group — after the # WAF header and before # Database
    // — so it is NOT down in the later # Services section.
    $titles = widgetTitles($body);
    expect(array_search('# WAF', $titles, true))->toBeLessThan(array_search('Search rate-limit blocks', $titles, true))
        ->and(array_search('Search rate-limit blocks', $titles, true))->toBeLessThan(array_search('# Database', $titles, true));

    // Without Typesense the panel is absent even when the WebACL exists.
    expect(findWidget(Dashboard::body(dashboardContext(['wafWebAcl' => 'yolo-testing-waf'])), 'Search rate-limit blocks'))->toBeNull();
});

it('charts Aurora DML throughput for a cluster and read/write IOPS for a plain instance', function (): void {
    // Aurora cluster (the default context) → per-statement DML breakdown, which
    // only Aurora emits.
    $throughput = findWidget(Dashboard::body(dashboardContext()), 'RDS throughput');
    expect($throughput)->not->toBeNull()
        ->and(collect($throughput['properties']['metrics'])->map(fn ($m): mixed => $m[1])->all())
        ->toBe(['SelectThroughput', 'InsertThroughput', 'UpdateThroughput', 'DeleteThroughput']);
    expect(findWidget(Dashboard::body(dashboardContext()), 'RDS IOPS'))->toBeNull();

    // Plain instance → those DML metrics never publish; chart read/write IOPS,
    // which every RDS engine emits, dimensioned on the instance id.
    $instance = Dashboard::body(dashboardContext(['rds' => ['identifier' => 'my-db', 'cluster' => false]]));
    expect(findWidget($instance, 'RDS throughput'))->toBeNull();

    $iops = findWidget($instance, 'RDS IOPS');
    expect($iops)->not->toBeNull()
        ->and(collect($iops['properties']['metrics'])->map(fn ($m): mixed => $m[1])->all())->toBe(['ReadIOPS', 'WriteIOPS'])
        ->and($iops['properties']['metrics'][0])->toContain('AWS/RDS', 'DBInstanceIdentifier', 'my-db');
});

it('splits a cluster\'s CPU and connections into writer and reader series — a plain instance charts one', function (): void {
    // The READER role is a static aggregate dimension: readers chart without
    // enumerating members, so the body stays deterministic as readers scale.
    $cpu = findWidget(Dashboard::body(dashboardContext()), 'RDS CPU');
    expect(collect($cpu['properties']['metrics'])->map(fn (array $m): mixed => end($m)['label'])->all())->toBe(['Writer', 'Readers'])
        ->and($cpu['properties']['metrics'][0])->toContain('DBClusterIdentifier', 'my-cluster', 'Role', 'WRITER')
        ->and($cpu['properties']['metrics'][1])->toContain('DBClusterIdentifier', 'my-cluster', 'Role', 'READER');

    $connections = findWidget(Dashboard::body(dashboardContext()), 'RDS connections');
    expect($connections['properties']['metrics'])->toHaveCount(2);

    $instance = Dashboard::body(dashboardContext(['rds' => ['identifier' => 'my-db', 'cluster' => false]]));
    expect(findWidget($instance, 'RDS CPU')['properties']['metrics'])->toHaveCount(1)
        ->and(findWidget($instance, 'RDS connections')['properties']['metrics'])->toHaveCount(1);
});

it('charts Aurora replica lag for a cluster only', function (): void {
    $lag = findWidget(Dashboard::body(dashboardContext()), 'Aurora replica lag');
    expect($lag)->not->toBeNull()
        ->and($lag['properties']['metrics'][0])->toContain('AWS/RDS', 'AuroraReplicaLag', 'DBClusterIdentifier', 'my-cluster', 'Role', 'READER');

    expect(findWidget(Dashboard::body(dashboardContext(['rds' => ['identifier' => 'my-db', 'cluster' => false]])), 'Aurora replica lag'))->toBeNull();
});

it('creates the dashboard when it does not exist (apply) and reports WOULD_CREATE on a dry-run', function (): void {
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

it('fails the sync when the declared database does not exist — a manifest error, not an omitted panel', function (): void {
    writeManifest(['region' => 'ap-southeast-2', 'account-id' => '111111111111', 'database' => 'not-created-yet']);

    $captured = [];
    bindMockRdsClient([
        'DescribeDBClusters' => new Result(['DBClusters' => []]),
        'DescribeDBInstances' => new Result(['DBInstances' => []]),
    ], $captured);

    // Declaring a database ahead of creating it must not read as a clean sync —
    // the key is declared once the database exists, and the message says so.
    expect(fn (): array => (new Dashboard())->resolveContext())
        ->toThrow(ResourceDoesNotExistException::class, 'Create the database first');
});

it('charts the database once it exists', function (): void {
    writeManifest(['region' => 'ap-southeast-2', 'account-id' => '111111111111', 'database' => 'app-db']);

    $captured = [];
    bindMockRdsClient([
        'DescribeDBClusters' => new Result(['DBClusters' => []]),
        'DescribeDBInstances' => new Result(['DBInstances' => [['DBInstanceIdentifier' => 'app-db']]]),
    ], $captured);

    expect((new Dashboard())->resolveContext()['rds'])->toBe(['identifier' => 'app-db', 'cluster' => false]);
});

it('makes no write when the live body already matches', function (): void {
    bindDashboardEnv("APP_ENV=production\n");

    $desired = Dashboard::body((new Dashboard())->resolveContext());

    $captured = [];
    bindMockCloudWatchClient([
        'GetDashboard' => new Result(['DashboardBody' => json_encode($desired)]),
    ], $captured);

    expect((new SyncCloudWatchDashboardStep())(['dry-run' => false]))->toBe(StepResult::SYNCED);
    expect(collect($captured)->pluck('name'))->not->toContain('PutDashboard');
});

it('rewrites a drifted dashboard on apply and only reports it on a dry-run', function (): void {
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

it('builds a console deep link from its name and the env region', function (): void {
    writeManifest(['region' => 'ap-southeast-2']);

    expect((new Dashboard())->consoleUrl())
        ->toBe('https://ap-southeast-2.console.aws.amazon.com/cloudwatch/home?region=ap-southeast-2#dashboards/dashboard/yolo-testing-my-app-dashboard');
});

it('returns no console link when the env declares no region', function (): void {
    writeManifest([]);

    expect((new Dashboard())->consoleUrl())->toBeNull();
});
