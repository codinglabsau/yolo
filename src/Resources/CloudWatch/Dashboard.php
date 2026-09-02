<?php

namespace Codinglabs\Yolo\Resources\CloudWatch;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\WafV2;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Aws\CloudFront;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Services\Alerts;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;
use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * Not a Resource: a dashboard carries no meaningful tags (CloudWatch only tags
 * alarms) and PutDashboard is a pure upsert. The body is built purely from a
 * resolved context; the AWS-assigned ids (ALB/target-group suffixes, distribution
 * id) are looked up live and their widget groups omitted until the resource exists,
 * so a greenfield first sync lands those panels on the next sync.
 */
class Dashboard implements Deletable
{
    protected const CPU_SCALE_THRESHOLD = 60;

    protected const CPU_CRITICAL_THRESHOLD = 80;

    protected const RESPONSE_TIME_TARGET = 0.25;

    // A reference line only — nothing alarms on TargetResponseTime.
    protected const RESPONSE_TIME_SLO = 1.5;

    protected const EXPECTED_HEALTHY_HOSTS = 1;

    // The aspiration; Alerts::WEB_5XX_RATE_PERCENT is what pages.
    protected const ERROR_RATE_SLO = 1;

    // Public: service definitions reference the palette in their own `# Services` widgets.
    public const BLUE = '#1f77b4';

    public const GREEN = '#2ca02c';

    public const ORANGE = '#ff7f0e';

    public const RED = '#d62728';

    public const PURPLE = '#9467bd';

    public function name(): string
    {
        return Helpers::keyedResourceName('dashboard');
    }

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

    /** deleteDashboards is idempotent on a missing dashboard. */
    public function delete(): void
    {
        Aws::cloudWatch()->deleteDashboards([
            'DashboardNames' => [$this->name()],
        ]);
    }

    /**
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
            $this->name(),
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
     * @return array<string, mixed>
     */
    public function resolveContext(): array
    {
        $web = Manifest::hasWeb();

        return [
            'region' => Manifest::get('region'),
            'web' => $web,
            // Only an autoscaling Octane tier emits the saturation metric — the same
            // gate the runtime reporter and Caddyfile key off.
            'burst' => $web && Manifest::usesMetricsCaddyfile(),
            'clusterName' => $web ? (new EcsCluster())->name() : null,
            'serviceName' => $web ? (new EcsService())->name() : null,
            // A bundled queue/scheduler rides web's section; the cluster only exists with web.
            'queueService' => $web && Manifest::hasStandaloneQueue() ? (new EcsService(ServerGroup::QUEUE))->name() : null,
            'schedulerService' => $web && Manifest::hasStandaloneScheduler() ? (new EcsService(ServerGroup::SCHEDULER))->name() : null,
            'albSuffix' => $web ? static::tryResolve(fn (): string => static::loadBalancerDimension((new LoadBalancer())->arn())) : null,
            // Env-shared, so looked up live rather than derived from this app's manifest.
            'wafWebAcl' => $web ? static::tryResolve(fn (): string => WafV2::webAcl((new WebAcl())->name())['Name']) : null,
            'targetGroupSuffix' => $web ? static::tryResolve(fn (): string => static::targetGroupDimension((new TargetGroup())->arn())) : null,
            'distributionId' => $web ? static::tryResolve(fn () => CloudFront::distributionByComment((new AssetDistribution())->name())['Id']) : null,
            'queuePrefix' => Helpers::keyedResourceName() . '-',
            'queues' => static::queueNames(),
            // `tasks.queue: false` runs jobs inline and YOLO melts the SQS queue.
            'queueDisabled' => Manifest::queueDisabled(),
            // Deliberately NOT tryResolve: a declared database that resolves to nothing
            // is a manifest error, and failing the sync is how it surfaces — omitting
            // the panel would let a mistyped identifier read as a clean sync.
            'rds' => Rds::target(),
            // null just omits the capacity-derived alarm lines.
            'databaseWriterClass' => ($target = Rds::target()) !== null && $target['cluster']
                ? Alerts::writerClass($target['identifier'])
                : null,
            'cacheNodeId' => (new CacheCluster())->exists() ? (new CacheCluster())->name() . '-001' : null,
            'buckets' => static::bucketNames(),
            'taskLogGroup' => $web ? (new TaskLogGroup())->name() : null,
            // Each definition always returns its keys (null/false when unused) so the body builder can rely on them.
            ...static::servicesContext(),
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
     * @return array<int, string>
     */
    protected static function queueNames(): array
    {
        if (Manifest::fansQueuesPerTenant()) {
            return collect(['landlord', ...array_keys(Manifest::tenants())])
                ->flatMap(fn (string $scope): array => Helpers::queueNames($scope))
                ->all();
        }

        return Helpers::queueNames();
    }

    /**
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

    public static function loadBalancerDimension(string $arn): string
    {
        $position = strpos($arn, ':loadbalancer/');

        return $position === false ? $arn : substr($arn, $position + strlen(':loadbalancer/'));
    }

    public static function targetGroupDimension(string $arn): string
    {
        $position = strpos($arn, ':targetgroup/');

        return $position === false ? $arn : substr($arn, $position + 1);
    }

    /**
     * The ALB is shared across the env, so the bare `LoadBalancer` dimension sums
     * every app; pairing it with the `TargetGroup` narrows to this app. Falls back to
     * the ALB alone before the target group exists (first sync).
     *
     * @return array<int, string>
     */
    protected static function appAlbDimensions(?string $targetGroup, string $alb): array
    {
        return $targetGroup !== null
            ? ['TargetGroup', $targetGroup, 'LoadBalancer', $alb]
            : ['LoadBalancer', $alb];
    }

    /**
     * Assembled purely from a resolved context so tests can assert it without AWS.
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

        [$section, $y] = static::queueSection($context, $y);
        $widgets = [...$widgets, ...$section];

        // A bundled scheduler rides web's compute.
        if ($context['schedulerService'] !== null) {
            [$section, $y] = static::groupComputeSection('# Scheduler', $context['clusterName'], $context['schedulerService'], $context['region'], $y);
            $widgets = [...$widgets, ...$section];
        }

        if ($context['wafWebAcl'] !== null) {
            [$section, $y] = static::wafSection($context, $y);
            $widgets = [...$widgets, ...$section];
        }

        if ($context['rds'] !== null) {
            [$section, $y] = static::databaseSection($context, $y);
            $widgets = [...$widgets, ...$section];
        }

        if ($context['cacheNodeId'] !== null) {
            [$section, $y] = static::cacheSection($context, $y);
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
            // A task can be "running" in ECS while the ALB has pulled it from rotation,
            // so target health is the truest availability signal.
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
                        ['color' => static::RED, 'label' => 'Min healthy', 'value' => static::EXPECTED_HEALTHY_HOSTS],
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
                    // ELB-generated 5xx isn't target-group attributable, so it can't go in
                    // a per-app rate — it surfaces on Target health and HTTP errors instead.
                    [['expression' => 'm1 / m2 * 100', 'label' => '5xx %', 'id' => 'e1', 'color' => static::RED]],
                    ['AWS/ApplicationELB', 'HTTPCode_Target_5XX_Count', ...static::appAlbDimensions($targetGroup, $alb), ['id' => 'm1', 'visible' => false]],
                    ['AWS/ApplicationELB', 'RequestCount', ...static::appAlbDimensions($targetGroup, $alb), ['id' => 'm2', 'visible' => false]],
                ],
                'annotations' => ['horizontal' => [
                    ['color' => static::ORANGE, 'label' => 'SLO', 'value' => static::ERROR_RATE_SLO],
                    ['color' => static::RED, 'label' => 'Alarm', 'value' => Alerts::WEB_5XX_RATE_PERCENT],
                ]],
            ]);
            $y += 6;

            $requests = [['AWS/ApplicationELB', 'RequestCount', ...static::appAlbDimensions($targetGroup, $alb), ['label' => 'Total requests', 'color' => static::BLUE]]];

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
                    ['AWS/ApplicationELB', 'TargetResponseTime', ...static::appAlbDimensions($targetGroup, $alb), ['label' => 'IQM', 'stat' => 'IQM', 'color' => static::BLUE]],
                    ['AWS/ApplicationELB', 'TargetResponseTime', ...static::appAlbDimensions($targetGroup, $alb), ['label' => 'p95', 'stat' => 'p95', 'color' => static::ORANGE]],
                    ['AWS/ApplicationELB', 'TargetResponseTime', ...static::appAlbDimensions($targetGroup, $alb), ['label' => 'p99', 'stat' => 'p99', 'color' => static::RED]],
                ],
                'annotations' => ['horizontal' => [
                    ['color' => static::RED, 'label' => 'SLO', 'value' => static::RESPONSE_TIME_SLO],
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
                    ['AWS/ApplicationELB', 'TargetResponseTime', ...static::appAlbDimensions($targetGroup, $alb), ['label' => '2-5s', 'stat' => 'TC(2:5)', 'color' => '#98df8a']],
                    ['AWS/ApplicationELB', 'TargetResponseTime', ...static::appAlbDimensions($targetGroup, $alb), ['label' => '5-10s', 'stat' => 'TC(5:10)', 'color' => static::ORANGE]],
                    ['AWS/ApplicationELB', 'TargetResponseTime', ...static::appAlbDimensions($targetGroup, $alb), ['label' => '10-30s', 'stat' => 'TC(10:30)', 'color' => static::RED]],
                    ['AWS/ApplicationELB', 'TargetResponseTime', ...static::appAlbDimensions($targetGroup, $alb), ['label' => '> 30s', 'stat' => 'TC(30:60)', 'color' => static::PURPLE]],
                ],
            ]);

            // 5-minute period: the ELB 5xx alarm evaluates a 5-minute Sum, so a
            // per-minute chart would overstate its threshold fivefold.
            $widgets[] = static::metric(12, $y, 12, 6, [
                'title' => 'HTTP errors',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 300,
                'stat' => 'Sum',
                'metrics' => [
                    ['AWS/ApplicationELB', 'HTTPCode_Target_4XX_Count', ...static::appAlbDimensions($targetGroup, $alb), ['label' => '4xx', 'color' => static::ORANGE]],
                    ['AWS/ApplicationELB', 'HTTPCode_Target_5XX_Count', ...static::appAlbDimensions($targetGroup, $alb), ['label' => 'Target 5xx', 'color' => static::RED]],
                    // ELB-generated 5xx has no target-group dimension, so this line is env-wide.
                    ['AWS/ApplicationELB', 'HTTPCode_ELB_5XX_Count', 'LoadBalancer', $alb, ['label' => 'ELB 5xx (LB-wide)', 'color' => static::PURPLE]],
                ],
                'annotations' => ['horizontal' => [
                    ['color' => static::RED, 'label' => 'ELB 5xx alarm', 'value' => Alerts::ALB_5XX_PER_FIVE_MINUTES],
                ]],
            ]);
            $y += 6;
        }

        // Maximum matches the burst alarm, which trips on the most-saturated task.
        if ($context['burst']) {
            $widgets[] = static::metric(0, $y, 12, 6, [
                'title' => 'Worker saturation',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => WebBurstPolicy::COOLDOWN,
                'yAxis' => ['left' => ['min' => 0, 'max' => 100]],
                'metrics' => [
                    [WebBurstPolicy::METRIC_NAMESPACE, WebBurstPolicy::METRIC_NAME, WebBurstPolicy::METRIC_DIMENSION, $service, ['label' => 'Busiest task', 'stat' => 'Maximum', 'color' => static::ORANGE]],
                ],
                'annotations' => ['horizontal' => [
                    ['color' => static::ORANGE, 'label' => 'Burst', 'value' => WebBurstPolicy::ALARM_THRESHOLD],
                    // Explains the metric's absence below the floor (not a gap in coverage).
                    ['color' => static::BLUE, 'label' => 'Emit floor', 'value' => WebBurstPolicy::EMIT_FLOOR],
                ]],
            ]);
            $y += 6;
        }

        [$compute, $y] = static::computeWidgets($region, $cluster, $service, $y, cpuScaled: true);

        return [[...$widgets, ...$compute], $y];
    }

    /**
     * $cpuScaled draws the CPU `Scale` line — only web scales on CPU. The queue
     * scales on backlog-per-task and the scheduler is a pinned singleton, so the
     * line would imply a trigger that doesn't exist.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function computeWidgets(string $region, string $cluster, string $service, int $y, bool $cpuScaled = false): array
    {
        $widgets = [
            static::metric(0, $y, 12, 6, [
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
                    ...$cpuScaled ? [['color' => static::ORANGE, 'label' => 'Scale', 'value' => static::CPU_SCALE_THRESHOLD]] : [],
                    ['color' => static::RED, 'label' => 'Critical', 'value' => static::CPU_CRITICAL_THRESHOLD],
                ]],
            ]),
            static::metric(12, $y, 12, 6, [
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
            ]),
        ];
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
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function groupComputeSection(string $title, string $cluster, string $service, string $region, int $y): array
    {
        $widgets = [static::header($y, $title)];
        $y++;

        [$compute, $y] = static::computeWidgets($region, $cluster, $service, $y);

        return [[...$widgets, ...$compute], $y];
    }

    /**
     * WebACL metrics are env-shared, dimensioned on ACL + region + rule. The Counted
     * series picks up anything left in Count (the Core Rule Set's body-size carve-out).
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

        // Rule names mirror WebAcl's skeleton.
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

        $servicePanels = static::serviceWafPanels($context);

        foreach ($servicePanels as $index => $properties) {
            $widgets[] = static::metric($index % 2 === 0 ? 0 : 12, $y, 12, 6, $properties);

            if ($index % 2 === 1) {
                $y += 6;
            }
        }

        if (count($servicePanels) % 2 === 1) {
            $y += 6;
        }

        return [$widgets, $y];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    protected static function serviceWafPanels(array $context): array
    {
        $widgets = [];

        foreach (Service::definitions() as $definition) {
            $widgets = [...$widgets, ...$definition->wafPanels($context)];
        }

        return $widgets;
    }

    /**
     * Self-omits when the queue is disabled and bundled (no service, no SQS).
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function queueSection(array $context, int $y): array
    {
        $service = $context['queueService'];
        $hasBacklog = ! $context['queueDisabled'];

        if ($service === null && ! $hasBacklog) {
            return [[], $y];
        }

        $widgets = [static::header($y, '# Queue')];
        $y++;

        if ($service !== null) {
            [$compute, $y] = static::computeWidgets($context['region'], $context['clusterName'], $service, $y);
            $widgets = [...$widgets, ...$compute];
        }

        if ($hasBacklog) {
            [$backlog, $y] = static::queueBacklogWidgets($context, $y);
            $widgets = [...$widgets, ...$backlog];
        }

        return [$widgets, $y];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function queueBacklogWidgets(array $context, int $y): array
    {
        $region = $context['region'];
        $queues = $context['queues'];

        $series = fn (string $metric) => collect($queues)
            ->map(fn (string $queue): array => ['AWS/SQS', $metric, 'QueueName', $queue, ['label' => static::queueLabel($queue, $context['queuePrefix'])]])
            ->all();

        // No DLQ panel: the Queue resource sets no RedrivePolicy, so there is no DLQ to chart.
        $widgets = [];

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'Queue depth',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => true,
            'period' => 60,
            'stat' => 'Maximum',
            'metrics' => $series('ApproximateNumberOfMessagesVisible'),
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
     * A cluster is charted through the static Role dimensions (WRITER follows
     * failovers, READER aggregates whatever readers exist) so the body never
     * enumerates members — a dynamic set would make the plan drift run-to-run.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function databaseSection(array $context, int $y): array
    {
        $region = $context['region'];
        $rds = $context['rds'];

        $metric = fn (string $name, array $options = []): array => ['AWS/RDS', $name, ...static::rdsDimensions($rds), $options];
        $reader = fn (string $name, array $options = []): array => ['AWS/RDS', $name, 'DBClusterIdentifier', $rds['identifier'], 'Role', 'READER', $options];

        $widgets = [static::header($y, '# Database')];
        $y++;

        // Alert alarms exist only for a cluster (WRITER role); the capacity pair only
        // when the writer's class is tabulated (not Serverless v2).
        $capacityClass = $context['databaseWriterClass'] !== null
            && $context['databaseWriterClass'] !== 'db.serverless'
            && array_key_exists($context['databaseWriterClass'], Alerts::AURORA_CLASSES)
                ? $context['databaseWriterClass']
                : null;

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'RDS CPU',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'yAxis' => ['left' => ['min' => 0, 'max' => 100]],
            'metrics' => $rds['cluster']
                ? [$metric('CPUUtilization', ['label' => 'Writer', 'color' => static::BLUE]), $reader('CPUUtilization', ['label' => 'Readers', 'color' => static::PURPLE])]
                : [$metric('CPUUtilization')],
            ...$rds['cluster'] ? ['annotations' => ['horizontal' => [
                ['color' => static::RED, 'label' => 'Alarm', 'value' => Alerts::DATABASE_CPU_PERCENT],
            ]]] : [],
        ]);

        $widgets[] = static::metric(12, $y, 12, 6, [
            'title' => 'RDS connections',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'metrics' => $rds['cluster']
                ? [$metric('DatabaseConnections', ['label' => 'Writer', 'color' => static::BLUE]), $reader('DatabaseConnections', ['label' => 'Readers', 'color' => static::PURPLE])]
                : [$metric('DatabaseConnections')],
            ...$capacityClass !== null ? ['annotations' => ['horizontal' => [
                ['color' => static::RED, 'label' => 'Alarm', 'value' => Alerts::databaseConnectionsCeiling($capacityClass)],
            ]]] : [],
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
            ...$capacityClass !== null ? ['annotations' => ['horizontal' => [
                ['color' => static::RED, 'label' => 'Alarm', 'value' => Alerts::databaseMemoryFloorBytes($capacityClass)],
            ]]] : [],
        ]);

        // The *Throughput metrics are Aurora-only; a plain instance gets IOPS instead.
        // Cluster-ness is Rds::target()'s memoised classification — stable across
        // tiers, so the body stays deterministic.
        $widgets[] = $rds['cluster']
            ? static::metric(12, $y, 12, 6, [
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
            ])
            : static::metric(12, $y, 12, 6, [
                'title' => 'RDS IOPS',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Average',
                'yAxis' => ['left' => ['min' => 0]],
                'metrics' => [
                    $metric('ReadIOPS', ['label' => 'Read', 'color' => static::BLUE]),
                    $metric('WriteIOPS', ['label' => 'Write', 'color' => static::ORANGE]),
                ],
            ]);
        $y += 6;

        // Latency climbs well before CPU or connections saturate.
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

        // Aggregate READER role: no member enumeration; empty while writer-only.
        if ($rds['cluster']) {
            $widgets[] = static::metric(12, $y, 12, 6, [
                'title' => 'Aurora replica lag',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Average',
                'yAxis' => ['left' => ['min' => 0]],
                'metrics' => [
                    $reader('AuroraReplicaLag', ['label' => 'Avg', 'color' => static::BLUE]),
                    $reader('AuroraReplicaLag', ['label' => 'Max', 'stat' => 'Maximum', 'color' => static::ORANGE]),
                ],
            ]);
        }
        $y += 6;

        // Aurora-only, like the buffer-cache alarm it backs.
        if ($rds['cluster']) {
            $widgets[] = static::metric(0, $y, 12, 6, [
                'title' => 'Aurora buffer cache hit ratio',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Average',
                'yAxis' => ['left' => ['min' => 0, 'max' => 100, 'showUnits' => false]],
                'metrics' => [$metric('BufferCacheHitRatio', ['label' => 'Writer', 'color' => static::BLUE])],
                'annotations' => ['horizontal' => [
                    ['color' => static::RED, 'label' => 'Alarm', 'value' => Alerts::DATABASE_BUFFER_CACHE_PERCENT],
                ]],
            ]);
            $y += 6;
        }

        return [$widgets, $y];
    }

    /**
     * Evictions at the alarm's 5-minute period so the line means what the alarm means.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    protected static function cacheSection(array $context, int $y): array
    {
        $region = $context['region'];
        $node = $context['cacheNodeId'];

        $widgets = [static::header($y, '# Cache')];
        $y++;

        $widgets[] = static::metric(0, $y, 12, 6, [
            'title' => 'Valkey memory',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'yAxis' => ['left' => ['min' => 0, 'max' => 100, 'showUnits' => false]],
            'metrics' => [
                ['AWS/ElastiCache', 'DatabaseMemoryUsagePercentage', 'CacheClusterId', $node, ['label' => 'Memory %', 'color' => static::BLUE]],
            ],
            'annotations' => ['horizontal' => [
                ['color' => static::RED, 'label' => 'Alarm', 'value' => Alerts::VALKEY_MEMORY_PERCENT],
            ]],
        ]);

        $widgets[] = static::metric(12, $y, 12, 6, [
            'title' => 'Valkey evictions',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 300,
            'stat' => 'Sum',
            'yAxis' => ['left' => ['min' => 0]],
            'metrics' => [
                ['AWS/ElastiCache', 'Evictions', 'CacheClusterId', $node, ['label' => 'Evictions / 5 min', 'color' => static::ORANGE]],
            ],
            'annotations' => ['horizontal' => [
                ['color' => static::RED, 'label' => 'Alarm', 'value' => Alerts::VALKEY_EVICTIONS_PER_FIVE_MINUTES],
            ]],
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

        if (count($serviceWidgets) % 2 === 1) {
            $y += 6;
        }

        return [$widgets, $y];
    }

    /**
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
