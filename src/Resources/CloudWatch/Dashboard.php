<?php

namespace Codinglabs\Yolo\Resources\CloudWatch;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\WafV2;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Aws\CloudFront;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;
use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A per-app CloudWatch dashboard giving at-a-glance visibility across every
 * service YOLO provisions for the app — the ECS service (web + in-task queue &
 * scheduler), the ALB it sits behind, its SQS queues, the asset CloudFront
 * distribution, its S3 buckets and its log groups — plus the RDS database it
 * connects to (declared by the manifest `database:` key; see rdsTarget()).
 *
 * A standalone reconciler, NOT a Resource: a dashboard carries no meaningful tags
 * (CloudWatch only tags alarms / Contributor Insights rules) and PutDashboard is a
 * pure upsert with no create/update split. It is dry-run honest — it reads the
 * live body, diffs it against the desired body (key-order-independent) and only
 * writes on drift, so `sync --dry-run` reports exactly when the dashboard would
 * change.
 *
 * The widget body is built from a resolved context: names are derived (ECS, SQS,
 * S3, log groups), the RDS identifier comes from the manifest, and the three
 * AWS-assigned identifiers (the ALB and target-group ARN suffixes and the
 * CloudFront distribution id) are looked up live and their widget groups omitted
 * if the backing resource doesn't exist yet — so on a greenfield first sync those
 * panels land on the next sync once their resources are provisioned.
 */
class Dashboard
{
    // Sensible default annotation thresholds. The queue-depth line instead
    // reuses sqs.depth-alarm-threshold so the graph and the existing
    // QueueAlarm agree. CPU bands stay hardcoded until the web-scaling config
    // lands with autoscaling, at which point the scale line reads from it.
    protected const CPU_SCALE_THRESHOLD = 60;

    protected const CPU_CRITICAL_THRESHOLD = 80;

    protected const RESPONSE_TIME_TARGET = 0.25;

    protected const RESPONSE_TIME_ALARM = 1.5;

    // The ALB should always have at least one task in rotation; anything below
    // this floor means the service is degraded or fully out of rotation.
    protected const EXPECTED_HEALTHY_HOSTS = 1;

    // 5xx SLO line (% of requests). A sustained breach is a user-facing outage.
    protected const ERROR_RATE_SLO = 1;

    // Public: service definitions reference the palette when composing their
    // own `# Services` widgets (ServiceDefinition::servicesWidgets()).
    public const BLUE = '#1f77b4';

    public const GREEN = '#2ca02c';

    public const ORANGE = '#ff7f0e';

    public const RED = '#d62728';

    public const PURPLE = '#9467bd';

    public function name(): string
    {
        return Helpers::keyedResourceName('dashboard');
    }

    /**
     * Best-effort AWS Console deep link to this dashboard, from its name + the
     * env region. `yolo status` surfaces it so an operator can jump from the
     * terminal summary to the full graphed dashboard; null when no region is set.
     */
    public function consoleUrl(): ?string
    {
        $region = (string) Manifest::get('region');

        if ($region === '') {
            return null;
        }

        return sprintf(
            'https://%s.console.aws.amazon.com/cloudwatch/home?region=%s#dashboards/dashboard/%s',
            $region,
            $region,
            $this->name(),
        );
    }

    public function exists(): bool
    {
        try {
            CloudWatch::dashboard($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    /**
     * Build the desired body, diff it against the live dashboard, and (only when
     * $apply and something drifted) push it. Returns the drift as Change[] so the
     * step reports WOULD_SYNC / SYNCED and the apply pass survives the
     * only-pending-steps filter.
     *
     * @return array<int, Change>
     */
    public function synchronise(bool $apply): array
    {
        $desired = static::body($this->resolveContext());
        $live = $this->liveBody();

        if (Helpers::documentsEqual($live, $desired)) {
            return [];
        }

        if ($apply) {
            Aws::cloudWatch()->putDashboard([
                'DashboardName' => $this->name(),
                'DashboardBody' => json_encode($desired),
            ]);
        }

        return [Change::make(
            'dashboard',
            $live === null ? 'absent' : 'drifted',
            sprintf('%d widgets', count($desired['widgets'])),
        )];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function liveBody(): ?array
    {
        try {
            return CloudWatch::dashboard($this->name());
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * Resolve every value the body needs. Names are derived; the RDS identifier
     * comes from the manifest; the three AWS-assigned identifiers are looked up live
     * and left null (→ widget omitted) when their resource doesn't exist yet.
     *
     * @return array<string, mixed>
     */
    public function resolveContext(): array
    {
        $web = Manifest::hasWeb();

        return [
            'region' => Manifest::get('region'),
            'web' => $web,
            'clusterName' => $web ? (new EcsCluster())->name() : null,
            'serviceName' => $web ? (new EcsService())->name() : null,
            'albSuffix' => $web ? static::tryResolve(fn (): string => static::loadBalancerDimension((new LoadBalancer())->arn())) : null,
            // The WAF is env-shared (one ACL fronts the ALB), so it's looked up live
            // rather than derived from this app's manifest — the panel shows for any
            // app behind the ALB, and is omitted until the ACL exists.
            'wafWebAcl' => $web ? static::tryResolve(fn (): string => WafV2::webAcl((new WebAcl())->name())['Name']) : null,
            'targetGroupSuffix' => $web ? static::tryResolve(fn (): string => static::targetGroupDimension((new TargetGroup())->arn())) : null,
            'distributionId' => $web ? static::tryResolve(fn () => CloudFront::distributionByComment((new AssetDistribution())->name())['Id']) : null,
            'queuePrefix' => Helpers::keyedResourceName() . '-',
            'queues' => static::queueNames(),
            'rds' => static::rdsTarget(),
            'buckets' => static::bucketNames(),
            'taskLogGroup' => $web ? (new TaskLogGroup())->name() : null,
            // Each service definition contributes its own context entries —
            // always returning its keys (null/false when the app doesn't
            // consume the service) so the body builder can rely on them.
            ...static::servicesContext(),
            'depthThreshold' => (int) Manifest::get('sqs.depth-alarm-threshold', 100),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function servicesContext(): array
    {
        $context = [];

        foreach (Service::definitions() as $definition) {
            $context = [...$context, ...$definition->dashboardContext()];
        }

        return $context;
    }

    /**
     * @param  callable(): string  $resolve
     */
    protected static function tryResolve(callable $resolve): ?string
    {
        try {
            return $resolve();
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * The app's SQS queue names, matching the queue steps: one for a solo app, or
     * the landlord queue plus one per tenant for a multi-tenant app.
     *
     * @return array<int, string>
     */
    protected static function queueNames(): array
    {
        if (Manifest::isMultitenanted()) {
            return [
                Helpers::keyedResourceName('landlord'),
                ...collect(Manifest::tenants())->keys()->map(fn (string $id): string => Helpers::keyedResourceName($id))->all(),
            ];
        }

        return [Helpers::keyedResourceName()];
    }

    /**
     * The S3 buckets YOLO owns for the app: config and assets always, plus the
     * optional application data bucket when bucket is configured.
     *
     * @return array<int, string>
     */
    protected static function bucketNames(): array
    {
        return collect([
            Paths::s3ConfigBucket(),
            (new AssetBucket())->name(),
            Manifest::has('bucket') ? Paths::s3AppBucket() : null,
        ])->filter()->values()->all();
    }

    /**
     * The RDS metric target for the Database section, DECLARED by the flat
     * `database:` manifest key. Accepts either a bare RDS identifier (charted as a
     * plain instance via DBInstanceIdentifier) or a full endpoint hostname — which
     * auto-detects an Aurora cluster (DBClusterIdentifier + Role=WRITER, so it
     * follows failovers) vs an instance, and skips an RDS Proxy / non-RDS host.
     *
     * Read from the manifest, never the app's secret `.env`: the dashboard is
     * written by `yolo sync` (the admin tier, deliberately barred from reading
     * secrets) and checked by the deploy gate + status observer — so its desired
     * body MUST resolve identically under every tier, which a secret read can't
     * guarantee. Null when nothing's declared, omitting the section.
     *
     * @return array{identifier: string, cluster: bool}|null
     */
    public static function rdsTarget(): ?array
    {
        $database = Manifest::get('database');

        if (! is_string($database) || $database === '') {
            return null;
        }

        // A bare value is a plain instance identifier; a full endpoint hostname
        // self-describes its kind.
        if (! str_ends_with($database, '.rds.amazonaws.com')) {
            return ['identifier' => $database, 'cluster' => false];
        }

        // RDS Proxy endpoints don't map to a DB metric identifier.
        if (str_contains($database, '.proxy-')) {
            return null;
        }

        return [
            'identifier' => strtok($database, '.'),
            'cluster' => str_contains($database, '.cluster-'),
        ];
    }

    /**
     * The CloudWatch `LoadBalancer` dimension value (`app/{name}/{id}`) parsed
     * out of a full ALB ARN.
     */
    public static function loadBalancerDimension(string $arn): string
    {
        $position = strpos($arn, ':loadbalancer/');

        return $position === false ? $arn : substr($arn, $position + strlen(':loadbalancer/'));
    }

    /**
     * The CloudWatch `TargetGroup` dimension value (`targetgroup/{name}/{id}`)
     * parsed out of a full target-group ARN.
     */
    public static function targetGroupDimension(string $arn): string
    {
        $position = strpos($arn, ':targetgroup/');

        return $position === false ? $arn : substr($arn, $position + 1);
    }

    /**
     * The full dashboard document, assembled purely from a resolved context so it
     * can be asserted in tests without touching AWS.
     *
     * @param  array<string, mixed>  $context
     * @return array{widgets: array<int, array<string, mixed>>}
     */
    public static function body(array $context): array
    {
        $widgets = [];
        $y = 0;

        if ($context['web']) {
            [$section, $y] = static::webSection($context, $y);
            $widgets = [...$widgets, ...$section];
        }

        if ($context['wafWebAcl'] !== null) {
            [$section, $y] = static::wafSection($context, $y);
            $widgets = [...$widgets, ...$section];
        }

        [$section, $y] = static::queueSection($context, $y);
        $widgets = [...$widgets, ...$section];

        if ($context['rds'] !== null) {
            [$section, $y] = static::databaseSection($context, $y);
            $widgets = [...$widgets, ...$section];
        }

        [$section, $y] = static::storageSection($context, $y);
        $widgets = [...$widgets, ...$section];

        $serviceWidgets = static::serviceWidgets($context);

        if ($serviceWidgets !== []) {
            [$section, $y] = static::servicesSection($serviceWidgets, $y);
            $widgets = [...$widgets, ...$section];
        }

        [$section, $y] = static::logsSection($context, $y);
        $widgets = [...$widgets, ...$section];

        return ['widgets' => $widgets];
    }

    /**
     * ECS compute (one service — web + in-task queue & scheduler) and, when the
     * ALB is attached, the request / latency / error panels in front of it.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function webSection(array $context, int $y): array
    {
        $region = $context['region'];
        $cluster = $context['clusterName'];
        $service = $context['serviceName'];
        $alb = $context['albSuffix'];
        $targetGroup = $context['targetGroupSuffix'];

        $widgets = [static::header($y, '# Web')];
        $y++;

        if ($alb !== null) {
            // Availability & SLO headline: a task can be "running" in ECS while the
            // ALB has pulled it out of rotation, so target health is the truest
            // availability signal; the 5xx rate is the user-facing SLO.
            $errorRateX = 0;

            if ($targetGroup !== null) {
                $widgets[] = static::metric(0, $y, 12, 6, [
                    'title' => 'Target health',
                    'region' => $region,
                    'view' => 'timeSeries',
                    'stacked' => false,
                    'period' => 60,
                    'stat' => 'Average',
                    'yAxis' => ['left' => ['min' => 0]],
                    'metrics' => [
                        ['AWS/ApplicationELB', 'HealthyHostCount', 'TargetGroup', $targetGroup, 'LoadBalancer', $alb, ['label' => 'Healthy', 'stat' => 'Minimum', 'color' => static::GREEN]],
                        ['AWS/ApplicationELB', 'UnHealthyHostCount', 'TargetGroup', $targetGroup, 'LoadBalancer', $alb, ['label' => 'Unhealthy', 'stat' => 'Maximum', 'color' => static::RED]],
                    ],
                    'annotations' => ['horizontal' => [
                        ['color' => static::RED, 'label' => 'Min healthy', 'value' => static::EXPECTED_HEALTHY_HOSTS, 'fill' => 'below'],
                    ]],
                ]);
                $errorRateX = 12;
            }

            $widgets[] = static::metric($errorRateX, $y, 12, 6, [
                'title' => '5xx error rate',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Sum',
                'yAxis' => ['left' => ['min' => 0, 'showUnits' => false]],
                'metrics' => [
                    [['expression' => '(m1 + m2) / m3 * 100', 'label' => '5xx %', 'id' => 'e1', 'color' => static::RED]],
                    ['AWS/ApplicationELB', 'HTTPCode_Target_5XX_Count', 'LoadBalancer', $alb, ['id' => 'm1', 'visible' => false]],
                    ['AWS/ApplicationELB', 'HTTPCode_ELB_5XX_Count', 'LoadBalancer', $alb, ['id' => 'm2', 'visible' => false]],
                    ['AWS/ApplicationELB', 'RequestCount', 'LoadBalancer', $alb, ['id' => 'm3', 'visible' => false]],
                ],
                'annotations' => ['horizontal' => [
                    ['color' => static::RED, 'label' => 'SLO', 'value' => static::ERROR_RATE_SLO, 'fill' => 'above'],
                ]],
            ]);
            $y += 6;

            $requests = [['AWS/ApplicationELB', 'RequestCount', 'LoadBalancer', $alb, ['label' => 'Total requests', 'color' => static::BLUE]]];

            if ($targetGroup !== null) {
                $requests[] = ['AWS/ApplicationELB', 'RequestCountPerTarget', 'TargetGroup', $targetGroup, ['label' => 'Requests per task', 'color' => static::GREEN]];
            }

            $widgets[] = static::metric(0, $y, 12, 6, [
                'title' => 'Requests',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Sum',
                'metrics' => $requests,
            ]);

            $widgets[] = static::metric(12, $y, 12, 6, [
                'title' => 'Response time',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'p95',
                'yAxis' => ['left' => ['min' => 0]],
                'metrics' => [
                    ['AWS/ApplicationELB', 'TargetResponseTime', 'LoadBalancer', $alb, ['label' => 'IQM', 'stat' => 'IQM', 'color' => static::BLUE]],
                    ['AWS/ApplicationELB', 'TargetResponseTime', 'LoadBalancer', $alb, ['label' => 'p95', 'stat' => 'p95', 'color' => static::ORANGE]],
                    ['AWS/ApplicationELB', 'TargetResponseTime', 'LoadBalancer', $alb, ['label' => 'p99', 'stat' => 'p99', 'color' => static::RED]],
                ],
                'annotations' => ['horizontal' => [
                    ['color' => static::RED, 'label' => 'Alarm', 'value' => static::RESPONSE_TIME_ALARM, 'fill' => 'above'],
                    ['color' => static::GREEN, 'label' => 'Target', 'value' => static::RESPONSE_TIME_TARGET],
                ]],
            ]);
            $y += 6;

            $widgets[] = static::metric(0, $y, 12, 6, [
                'title' => 'Slow requests',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => true,
                'period' => 60,
                'yAxis' => ['left' => ['showUnits' => false]],
                'metrics' => [
                    ['AWS/ApplicationELB', 'TargetResponseTime', 'LoadBalancer', $alb, ['label' => '2-5s', 'stat' => 'TC(2:5)', 'color' => '#98df8a']],
                    ['AWS/ApplicationELB', 'TargetResponseTime', 'LoadBalancer', $alb, ['label' => '5-10s', 'stat' => 'TC(5:10)', 'color' => static::ORANGE]],
                    ['AWS/ApplicationELB', 'TargetResponseTime', 'LoadBalancer', $alb, ['label' => '10-30s', 'stat' => 'TC(10:30)', 'color' => static::RED]],
                    ['AWS/ApplicationELB', 'TargetResponseTime', 'LoadBalancer', $alb, ['label' => '> 30s', 'stat' => 'TC(30:60)', 'color' => static::PURPLE]],
                ],
            ]);

            $widgets[] = static::metric(12, $y, 12, 6, [
                'title' => 'HTTP errors',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Sum',
                'metrics' => [
                    ['AWS/ApplicationELB', 'HTTPCode_Target_4XX_Count', 'LoadBalancer', $alb, ['label' => '4xx', 'color' => static::ORANGE]],
                    ['AWS/ApplicationELB', 'HTTPCode_Target_5XX_Count', 'LoadBalancer', $alb, ['label' => 'Target 5xx', 'color' => static::RED]],
                    ['AWS/ApplicationELB', 'HTTPCode_ELB_5XX_Count', 'LoadBalancer', $alb, ['label' => 'ELB 5xx', 'color' => static::PURPLE]],
                ],
            ]);
            $y += 6;
        }

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'CPU utilisation',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'yAxis' => ['left' => ['min' => 0, 'max' => 100]],
            'metrics' => [
                ['AWS/ECS', 'CPUUtilization', 'ClusterName', $cluster, 'ServiceName', $service, ['label' => 'Average', 'color' => static::BLUE]],
                ['AWS/ECS', 'CPUUtilization', 'ClusterName', $cluster, 'ServiceName', $service, ['label' => 'Max', 'stat' => 'Maximum', 'color' => static::ORANGE]],
            ],
            'annotations' => ['horizontal' => [
                ['color' => static::ORANGE, 'label' => 'Scale', 'value' => static::CPU_SCALE_THRESHOLD],
                ['color' => static::RED, 'label' => 'Critical', 'value' => static::CPU_CRITICAL_THRESHOLD, 'fill' => 'above'],
            ]],
        ]);

        $widgets[] = static::metric(12, $y, 12, 6, [
            'title' => 'Memory utilisation',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'yAxis' => ['left' => ['min' => 0, 'max' => 100]],
            'metrics' => [
                ['AWS/ECS', 'MemoryUtilization', 'ClusterName', $cluster, 'ServiceName', $service, ['label' => 'Average', 'color' => static::BLUE]],
                ['AWS/ECS', 'MemoryUtilization', 'ClusterName', $cluster, 'ServiceName', $service, ['label' => 'Max', 'stat' => 'Maximum', 'color' => static::ORANGE]],
            ],
        ]);
        $y += 6;

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'Tasks (running vs desired)',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'yAxis' => ['left' => ['min' => 0]],
            'metrics' => [
                ['ECS/ContainerInsights', 'RunningTaskCount', 'ClusterName', $cluster, 'ServiceName', $service, ['label' => 'Running', 'color' => static::GREEN]],
                ['ECS/ContainerInsights', 'DesiredTaskCount', 'ClusterName', $cluster, 'ServiceName', $service, ['label' => 'Desired', 'color' => static::BLUE]],
            ],
        ]);

        $widgets[] = static::metric(12, $y, 12, 6, [
            'title' => 'Network in/out',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'metrics' => [
                ['ECS/ContainerInsights', 'NetworkRxBytes', 'ClusterName', $cluster, 'ServiceName', $service, ['label' => 'Rx', 'color' => static::BLUE]],
                ['ECS/ContainerInsights', 'NetworkTxBytes', 'ClusterName', $cluster, 'ServiceName', $service, ['label' => 'Tx', 'color' => static::ORANGE]],
            ],
        ]);
        $y += 6;

        return [$widgets, $y];
    }

    /**
     * SQS depth, throughput and oldest-message age across the app's queues.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    /**
     * The WAF panels: overall allow/block/count posture, and a per-rule blocked
     * breakdown showing where blocks come from. The disposition panel's Counted
     * series picks up anything left in Count (the Core Rule Set's body-size
     * carve-out). WebACL metrics are env-shared, dimensioned on ACL + region + rule.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function wafSection(array $context, int $y): array
    {
        $region = $context['region'];
        $webAcl = $context['wafWebAcl'];

        $series = fn (string $metric, string $rule, array $options): array => [
            'AWS/WAFV2', $metric, 'WebACL', $webAcl, 'Region', $region, 'Rule', $rule, $options,
        ];

        $widgets = [static::header($y, '# WAF')];
        $y++;

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'Request disposition',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Sum',
            'metrics' => [
                $series('AllowedRequests', 'ALL', ['label' => 'Allowed', 'color' => static::GREEN]),
                $series('BlockedRequests', 'ALL', ['label' => 'Blocked', 'color' => static::RED]),
                $series('CountedRequests', 'ALL', ['label' => 'Counted (would block)', 'color' => static::ORANGE]),
            ],
        ]);

        // Rule names mirror WebAcl's skeleton — every group blocks, so each is
        // charted as BlockedRequests showing where blocks originate.
        $widgets[] = static::metric(12, $y, 12, 6, [
            'title' => 'Blocked by rule',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => true,
            'period' => 60,
            'stat' => 'Sum',
            'metrics' => [
                $series('BlockedRequests', 'yolo-block-ips', ['label' => 'Block list', 'color' => static::RED]),
                $series('BlockedRequests', 'yolo-banned-countries', ['label' => 'Geo block', 'color' => static::BLUE]),
                $series('BlockedRequests', 'AWS-AWSManagedRulesAmazonIpReputationList', ['label' => 'IP reputation']),
                $series('BlockedRequests', 'AWS-AWSManagedRulesKnownBadInputsRuleSet', ['label' => 'Known bad inputs']),
                $series('BlockedRequests', 'AWS-AWSManagedRulesCommonRuleSet', ['label' => 'CRS', 'color' => static::ORANGE]),
                $series('BlockedRequests', 'AWS-AWSManagedRulesSQLiRuleSet', ['label' => 'SQLi']),
                $series('BlockedRequests', 'AWS-AWSManagedRulesPHPRuleSet', ['label' => 'PHP']),
                $series('BlockedRequests', 'yolo-rate-limit', ['label' => 'Rate limit', 'color' => static::PURPLE]),
            ],
        ]);
        $y += 6;

        return [$widgets, $y];
    }

    protected static function queueSection(array $context, int $y): array
    {
        $region = $context['region'];
        $queues = $context['queues'];

        $series = fn (string $metric) => collect($queues)
            ->map(fn (string $queue): array => ['AWS/SQS', $metric, 'QueueName', $queue, ['label' => static::queueLabel($queue, $context['queuePrefix'])]])
            ->all();

        // No dedicated dead-letter-queue depth panel: the Queue resource provisions
        // a plain SQS queue with no RedrivePolicy, so there is no DLQ to chart. If a
        // DLQ is ever added, a ">0 = silent job failures" panel belongs right here.
        $widgets = [static::header($y, '# Queue')];
        $y++;

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'Queue depth',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => true,
            'period' => 60,
            'stat' => 'Maximum',
            'metrics' => $series('ApproximateNumberOfMessagesVisible'),
            'annotations' => ['horizontal' => [
                ['label' => 'Depth alarm', 'value' => $context['depthThreshold'], 'color' => static::RED],
            ]],
        ]);

        $widgets[] = static::metric(12, $y, 12, 6, [
            'title' => 'Queue throughput',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => true,
            'period' => 60,
            'stat' => 'Sum',
            'metrics' => $series('NumberOfMessagesSent'),
        ]);
        $y += 6;

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'Oldest message age',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Maximum',
            'metrics' => $series('ApproximateAgeOfOldestMessage'),
        ]);
        $y += 6;

        return [$widgets, $y];
    }

    /**
     * RDS health for the database the app connects to (Aurora cluster writer or a
     * plain instance), derived from DB_HOST.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function databaseSection(array $context, int $y): array
    {
        $region = $context['region'];
        $rds = $context['rds'];

        $metric = fn (string $name, array $options = []): array => ['AWS/RDS', $name, ...static::rdsDimensions($rds), $options];

        $widgets = [static::header($y, '# Database')];
        $y++;

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'RDS CPU',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'yAxis' => ['left' => ['min' => 0, 'max' => 100]],
            'metrics' => [$metric('CPUUtilization')],
        ]);

        $widgets[] = static::metric(12, $y, 12, 6, [
            'title' => 'RDS connections',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'metrics' => [$metric('DatabaseConnections')],
        ]);
        $y += 6;

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'RDS freeable memory',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'metrics' => [$metric('FreeableMemory')],
        ]);

        $widgets[] = static::metric(12, $y, 12, 6, [
            'title' => 'RDS throughput',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => true,
            'period' => 60,
            'stat' => 'Sum',
            'metrics' => [
                $metric('SelectThroughput', ['label' => 'SELECT', 'color' => static::BLUE]),
                $metric('InsertThroughput', ['label' => 'INSERT', 'color' => static::GREEN]),
                $metric('UpdateThroughput', ['label' => 'UPDATE', 'color' => static::ORANGE]),
                $metric('DeleteThroughput', ['label' => 'DELETE', 'color' => static::RED]),
            ],
        ]);
        $y += 6;

        // Read/write latency is the earliest DB-degradation tell — it climbs well
        // before CPU or connections saturate. Seconds, p90 alongside the average.
        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'RDS read/write latency',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'yAxis' => ['left' => ['min' => 0]],
            'metrics' => [
                $metric('ReadLatency', ['label' => 'Read avg', 'color' => static::BLUE]),
                $metric('ReadLatency', ['label' => 'Read p90', 'stat' => 'p90', 'color' => static::PURPLE]),
                $metric('WriteLatency', ['label' => 'Write avg', 'color' => static::GREEN]),
                $metric('WriteLatency', ['label' => 'Write p90', 'stat' => 'p90', 'color' => static::ORANGE]),
            ],
        ]);
        $y += 6;

        return [$widgets, $y];
    }

    /**
     * @param  array{identifier: string, cluster: bool}  $rds
     * @return array<int, string>
     */
    protected static function rdsDimensions(array $rds): array
    {
        return $rds['cluster']
            ? ['DBClusterIdentifier', $rds['identifier'], 'Role', 'WRITER']
            : ['DBInstanceIdentifier', $rds['identifier']];
    }

    /**
     * The asset CloudFront distribution (omitted until it exists) and the app's
     * S3 buckets.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function storageSection(array $context, int $y): array
    {
        $region = $context['region'];
        $distributionId = $context['distributionId'];
        $buckets = $context['buckets'];

        $widgets = [static::header($y, '# CDN & storage')];
        $y++;

        if ($distributionId !== null) {
            $widgets[] = static::metric(0, $y, 12, 6, [
                'title' => 'Asset CDN — requests',
                'region' => 'us-east-1',
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Sum',
                'yAxis' => ['right' => ['showUnits' => false]],
                'metrics' => [
                    ['AWS/CloudFront', 'Requests', 'Region', 'Global', 'DistributionId', $distributionId, ['label' => 'Requests', 'color' => static::BLUE]],
                    ['AWS/CloudFront', '4xxErrorRate', 'Region', 'Global', 'DistributionId', $distributionId, ['label' => '4xx %', 'stat' => 'Average', 'color' => static::ORANGE, 'yAxis' => 'right']],
                    ['AWS/CloudFront', '5xxErrorRate', 'Region', 'Global', 'DistributionId', $distributionId, ['label' => '5xx %', 'stat' => 'Average', 'color' => static::RED, 'yAxis' => 'right']],
                ],
            ]);

            $widgets[] = static::metric(12, $y, 12, 6, [
                'title' => 'Asset CDN — data transfer (MB)',
                'region' => 'us-east-1',
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Sum',
                'metrics' => [
                    [['expression' => 'm1/1000000', 'label' => 'Downloaded MB', 'id' => 'e1', 'region' => 'us-east-1']],
                    ['AWS/CloudFront', 'BytesDownloaded', 'Region', 'Global', 'DistributionId', $distributionId, ['id' => 'm1', 'visible' => false]],
                ],
            ]);
            $y += 6;

            // A low cache hit rate means the CDN is passing traffic through to the
            // origin — more origin load, higher latency and higher transfer cost.
            $widgets[] = static::metric(0, $y, 12, 6, [
                'title' => 'Asset CDN — cache hit rate',
                'region' => 'us-east-1',
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Average',
                'yAxis' => ['left' => ['min' => 0, 'max' => 100, 'showUnits' => false]],
                'metrics' => [
                    ['AWS/CloudFront', 'CacheHitRate', 'Region', 'Global', 'DistributionId', $distributionId, ['label' => 'Cache hit %', 'color' => static::GREEN]],
                ],
            ]);
            $y += 6;
        }

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'S3 storage size',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 86400,
            'stat' => 'Average',
            'metrics' => collect($buckets)
                ->map(fn (string $bucket): array => ['AWS/S3', 'BucketSizeBytes', 'BucketName', $bucket, 'StorageType', 'StandardStorage', ['label' => $bucket]])
                ->all(),
        ]);

        $widgets[] = static::metric(12, $y, 12, 6, [
            'title' => 'S3 object count',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 86400,
            'stat' => 'Average',
            'metrics' => collect($buckets)
                ->map(fn (string $bucket): array => ['AWS/S3', 'NumberOfObjects', 'BucketName', $bucket, 'StorageType', 'AllStorageTypes', ['label' => $bucket]])
                ->all(),
        ]);
        $y += 6;

        return [$widgets, $y];
    }

    /**
     * One panel per consumed service with chartable CloudWatch metrics. Both
     * are account-level by nature (MediaConvert jobs share the account default
     * queue; Rekognition metrics carry only an Operation dimension), charted on
     * the consumer's dashboard because that's where the person debugging looks.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    /**
     * The `# Services` widget property maps every consumed service's
     * definition contributes, in enum order. Each renders as a half-width
     * panel; servicesSection packs them two per row.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    protected static function serviceWidgets(array $context): array
    {
        $widgets = [];

        foreach (Service::definitions() as $definition) {
            $widgets = [...$widgets, ...$definition->servicesWidgets($context)];
        }

        return $widgets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $serviceWidgets
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function servicesSection(array $serviceWidgets, int $y): array
    {
        $widgets = [static::header($y, '# Services')];
        $y++;

        foreach ($serviceWidgets as $index => $properties) {
            $widgets[] = static::metric($index % 2 === 0 ? 0 : 12, $y, 12, 6, $properties);

            if ($index % 2 === 1) {
                $y += 6;
            }
        }

        // An odd final panel still occupies its row.
        if (count($serviceWidgets) % 2 === 1) {
            $y += 6;
        }

        return [$widgets, $y];
    }

    /**
     * Logs Insights panels over the app's task log group plus whatever panels
     * each consumed service's definition contributes (e.g. the IVS log group).
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function logsSection(array $context, int $y): array
    {
        $region = $context['region'];

        $servicePanels = [];

        foreach (Service::definitions() as $definition) {
            $servicePanels = [...$servicePanels, ...$definition->logPanels($context)];
        }

        $logGroups = collect([
            'Application logs' => $context['taskLogGroup'],
            ...$servicePanels,
        ])->filter();

        if ($logGroups->isEmpty()) {
            return [[], $y];
        }

        $widgets = [static::header($y, '# Logs')];
        $y++;

        foreach ($logGroups as $title => $logGroup) {
            $widgets[] = [
                'type' => 'log',
                'x' => 0,
                'y' => $y,
                'width' => 24,
                'height' => 6,
                'properties' => [
                    'title' => $title,
                    'region' => $region,
                    'view' => 'table',
                    'query' => sprintf("SOURCE '%s' | fields @timestamp, @message\n| sort @timestamp desc\n| limit 100", $logGroup),
                ],
            ];
            $y += 6;
        }

        return [$widgets, $y];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    protected static function metric(int $x, int $y, int $width, int $height, array $properties): array
    {
        return [
            'type' => 'metric',
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function header(int $y, string $markdown): array
    {
        return [
            'type' => 'text',
            'x' => 0,
            'y' => $y,
            'width' => 24,
            'height' => 1,
            'properties' => ['markdown' => $markdown, 'background' => 'transparent'],
        ];
    }

    protected static function queueLabel(string $queue, string $prefix): string
    {
        return Str::startsWith($queue, $prefix) ? Str::after($queue, $prefix) : $queue;
    }
}
