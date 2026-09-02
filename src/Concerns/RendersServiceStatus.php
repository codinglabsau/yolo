<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Aws\Sqs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Tui\Chart;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Enums\ServerGroup;
use Symfony\Component\Console\Helper\Table;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;

/**
 * Every external read is defensive — a missing service, scalable target or metric
 * yields a null/"—" cell rather than crashing, so a half-provisioned or cold app
 * still renders.
 */
trait RendersServiceStatus
{
    /**
     * `withLoad` is off for the end-of-deploy recap, which never renders load — it
     * skips CloudWatch round-trips that would only add latency to every deploy.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function gatherServiceStatuses(bool $withLoad = true): array
    {
        return array_map(
            fn (ServerGroup $group): array => static::gatherServiceStatus($group, $withLoad),
            Manifest::serverGroups(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function gatherServiceStatus(ServerGroup $group, bool $withLoad = true): array
    {
        $cluster = (new EcsCluster())->name();
        $serviceName = (new EcsService($group))->name();

        $row = [
            'group' => $group,
            'exists' => false,
            'running' => 0,
            'desired' => 0,
            'pending' => 0,
            'launch' => 'FARGATE',
            'cpu' => null,
            'memory' => null,
            'revision' => null,
            'version' => null,
            'primary' => null,
            'rolloutState' => null,
            'rolloutReason' => null,
            'scaling' => null,
            'load' => static::emptyLoad(),
            'cpuTarget' => null,
        ];

        try {
            $service = Ecs::service($cluster, $serviceName);
        } catch (ResourceDoesNotExistException) {
            return $row;
        }

        $row = [...$row, 'exists' => true, ...static::serviceCore($service)];

        $row['scaling'] = static::gatherScaling($group);
        $row['cpuTarget'] = static::cpuTargetFrom($row['scaling']);

        if ($withLoad) {
            // 15-minute window so the inline sparkline has ~15 datapoints.
            $row['load'] = static::gatherLoad($group, $cluster, $serviceName, 900);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    protected static function serviceCore(array $service): array
    {
        $core = [
            'running' => (int) ($service['runningCount'] ?? 0),
            'desired' => (int) ($service['desiredCount'] ?? 0),
            'pending' => (int) ($service['pendingCount'] ?? 0),
            'launch' => static::launchType($service),
            'primary' => null,
            'rolloutState' => null,
            'rolloutReason' => null,
            'revision' => null,
            'cpu' => null,
            'memory' => null,
            'version' => null,
        ];

        $primary = collect($service['deployments'] ?? [])->firstWhere('status', 'PRIMARY');

        if ($primary === null) {
            return $core;
        }

        $taskDefinitionArn = $primary['taskDefinition'] ?? null;

        $core['primary'] = $primary;
        $core['rolloutState'] = $primary['rolloutState'] ?? null;
        $core['rolloutReason'] = $primary['rolloutStateReason'] ?? null;
        $core['revision'] = static::revisionLabel($taskDefinitionArn);

        try {
            $taskDefinition = $taskDefinitionArn === null ? [] : Ecs::taskDefinition($taskDefinitionArn);
            $core['cpu'] = $taskDefinition['cpu'] ?? null;
            $core['memory'] = $taskDefinition['memory'] ?? null;
            $core['version'] = static::versionFromImage($taskDefinition['containerDefinitions'][0]['image'] ?? '');
        } catch (ResourceDoesNotExistException) {
        }

        return $core;
    }

    /**
     * Apps are discovered from live ECS clusters via the `yolo-{env}-{app}` naming
     * convention, so no per-app manifest is needed.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function gatherEnvStatuses(string $environment): array
    {
        return array_map(
            fn (string $app): array => static::gatherAppRollup($environment, $app),
            Ecs::liveApps($environment),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function gatherAppRollup(string $environment, string $app): array
    {
        $cluster = "yolo-{$environment}-{$app}";

        $row = [
            'app' => $app,
            'exists' => false,
            'running' => 0,
            'desired' => 0,
            'pending' => 0,
            'launch' => 'FARGATE',
            'primary' => null,
            'rolloutState' => null,
            'rolloutReason' => null,
            'revision' => null,
            'cpu' => null,
            'memory' => null,
            'version' => null,
        ];

        try {
            // The most request-facing service that exists is the headline, so a
            // web-less worker app rolls up its queue/scheduler instead of "does not exist".
            $service = Ecs::firstService($cluster, array_map(
                fn (ServerGroup $group): string => "{$cluster}-{$group->value}",
                ServerGroup::cases(),
            ));
        } catch (ResourceDoesNotExistException) {
            return $row;
        }

        return [...$row, 'exists' => true, ...static::serviceCore($service)];
    }

    /**
     * Null when the group has no scalable target (a fixed-count service).
     *
     * @return array{min: int, max: int, policies: array<int, array{metric: string, target: float}>}|null
     */
    protected static function gatherScaling(ServerGroup $group): ?array
    {
        $bounds = (new ScalableTarget($group))->current();

        if ($bounds === null) {
            return null;
        }

        $policies = array_values(array_filter(array_map(
            static::policyView(...),
            ApplicationAutoScaling::scalingPolicies(ScalableTarget::resourceId($group)),
        )));

        // DescribeScalingPolicies returns no guaranteed order; without a canonical
        // one the overview reshuffles cpu/concurrency/burst between redraws.
        usort($policies, static fn (array $a, array $b): int => static::policyRank($a['metric']) <=> static::policyRank($b['metric']));

        return [...$bounds, 'policies' => $policies];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array{metric: string, target: float}|null
     */
    protected static function policyView(array $policy): ?array
    {
        $config = $policy['TargetTrackingScalingPolicyConfiguration'] ?? null;

        if ($config === null) {
            // Step scaling has no single target value — name it by the policy YOLO runs.
            $metric = str_contains($policy['PolicyName'] ?? '', 'burst') ? 'burst' : 'backlog';

            return ['metric' => $metric, 'target' => 0.0];
        }

        // A customized-metric target-tracking policy carries no PredefinedMetricType;
        // the web concurrency policy is recognised by its name instead.
        $metric = $config['PredefinedMetricSpecification']['PredefinedMetricType']
            ?? (str_contains($policy['PolicyName'] ?? '', 'concurrency') ? 'concurrency' : 'custom');

        return ['metric' => $metric, 'target' => (float) ($config['TargetValue'] ?? 0)];
    }

    protected static function policyRank(string $metric): int
    {
        return match ($metric) {
            'ECSServiceAverageCPUUtilization' => 0,
            'concurrency' => 1,
            'burst' => 2,
            'backlog' => 3,
            default => 4,
        };
    }

    protected static function cpuTargetFrom(?array $scaling): ?float
    {
        foreach ($scaling['policies'] ?? [] as $policy) {
            if ($policy['metric'] === 'ECSServiceAverageCPUUtilization') {
                return $policy['target'];
            }
        }

        return null;
    }

    /**
     * Each metric is read once as a 1-minute series: the last point is the live
     * reading and the series feeds the sparklines — one CloudWatch round-trip per
     * metric, not two.
     *
     * @return array{cpu: ?float, memory: ?float, requests: ?float, response: ?float, series: array{cpu: array<int, float>, memory: array<int, float>, requests: array<int, float>, response: array<int, float>}}
     */
    protected static function gatherLoad(ServerGroup $group, string $cluster, string $serviceName, int $lookback = 300): array
    {
        $dimensions = [
            ['Name' => 'ClusterName', 'Value' => $cluster],
            ['Name' => 'ServiceName', 'Value' => $serviceName],
        ];

        $cpu = CloudWatch::metricSeries('AWS/ECS', 'CPUUtilization', $dimensions, 'Average', 60, $lookback);
        $memory = CloudWatch::metricSeries('AWS/ECS', 'MemoryUtilization', $dimensions, 'Average', 60, $lookback);

        $load = static::emptyLoad();
        $load['cpu'] = static::latestOf($cpu);
        $load['memory'] = static::latestOf($memory);
        $load['series']['cpu'] = $cpu;
        $load['series']['memory'] = $memory;

        if ($group !== ServerGroup::WEB) {
            return $load;
        }

        $targetGroup = static::targetGroupDimension();

        if ($targetGroup !== null) {
            $albDimensions = [['Name' => 'TargetGroup', 'Value' => $targetGroup]];
            $requests = CloudWatch::metricSeries('AWS/ApplicationELB', 'RequestCountPerTarget', $albDimensions, 'Sum', 60, $lookback);
            $response = CloudWatch::metricSeries('AWS/ApplicationELB', 'TargetResponseTime', $albDimensions, 'Average', 60, $lookback);

            $load['requests'] = static::latestOf($requests);
            $load['response'] = static::latestOf($response);
            $load['series']['requests'] = $requests;
            $load['series']['response'] = $response;
        }

        return $load;
    }

    /**
     * A cold or missing service yields empty series (CloudWatch returns no
     * datapoints), which the chart renders as a "no data" frame rather than crashing.
     *
     * @return array<int, array{group: ServerGroup, load: array{cpu: ?float, memory: ?float, requests: ?float, response: ?float, series: array{cpu: array<int, float>, memory: array<int, float>, requests: array<int, float>, response: array<int, float>}}}>
     */
    public static function gatherMetricsSeries(int $minutes = 60): array
    {
        $cluster = (new EcsCluster())->name();

        return array_map(static fn (ServerGroup $group): array => [
            'group' => $group,
            'load' => static::gatherLoad($group, $cluster, (new EcsService($group))->name(), $minutes * 60),
        ], Manifest::serverGroups());
    }

    /**
     * Every row (even a cold service) carries the same keys so the `--json`
     * contract is stable.
     *
     * @return array{cpu: ?float, memory: ?float, requests: ?float, response: ?float, series: array{cpu: array<int, float>, memory: array<int, float>, requests: array<int, float>, response: array<int, float>}}
     */
    protected static function emptyLoad(): array
    {
        return [
            'cpu' => null,
            'memory' => null,
            'requests' => null,
            'response' => null,
            'series' => ['cpu' => [], 'memory' => [], 'requests' => [], 'response' => []],
        ];
    }

    /**
     * @param  array<int, float>  $series
     */
    protected static function latestOf(array $series): ?float
    {
        return $series === [] ? null : $series[array_key_last($series)];
    }

    /**
     * Surfaced app-level (not per ECS group) so the backlog shows even when the
     * queue worker is bundled into the web container.
     *
     * @return array<int, array{label: string, name: string, backlog: int}>
     */
    protected static function gatherQueueBacklogs(): array
    {
        $queues = [];

        foreach (static::queueNames() as $label => $name) {
            $backlog = Sqs::approximateMessages($name);

            if ($backlog !== null) {
                $queues[] = ['label' => $label, 'name' => $name, 'backlog' => $backlog];
            }
        }

        return $queues;
    }

    /**
     * @return array<string, string>
     */
    protected static function queueNames(): array
    {
        if (Manifest::fansQueuesPerTenant()) {
            $scopes = ['landlord' => 'landlord'];

            foreach (array_keys(Manifest::tenants()) as $tenantId) {
                $scopes[$tenantId] = $tenantId;
            }
        } else {
            $scopes = ['queue' => null];
        }

        $tiers = Manifest::queueTiers();
        $names = [];

        foreach ($scopes as $label => $scope) {
            foreach (Helpers::queueNames($scope) as $index => $name) {
                $names[$tiers === [] ? $label : "{$label}-{$tiers[$index]}"] = $name;
            }
        }

        return $names;
    }

    /**
     * Null when the app has no web target group (headless, or not yet synced).
     */
    protected static function targetGroupDimension(): ?string
    {
        try {
            $arn = (new TargetGroup())->arn();
        } catch (\Throwable) {
            return null;
        }

        $position = strpos($arn, ':targetgroup/');

        return $position === false ? null : substr($arn, $position + 1);
    }

    /**
     * Drops the raw `primary` deployment blob — its DateTimeInterface timestamps
     * don't belong in the `--json` contract.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, array<string, mixed>>
     */
    public static function jsonStatuses(array $statuses): array
    {
        return array_map(static::jsonStatus(...), $statuses);
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    public static function jsonStatus(array $status): array
    {
        return [
            'group' => $status['group']->value,
            'exists' => (bool) $status['exists'],
            'tasks' => [
                'running' => (int) $status['running'],
                'desired' => (int) $status['desired'],
                'pending' => (int) $status['pending'],
            ],
            'spec' => [
                'cpu' => $status['cpu'],
                'memory' => $status['memory'],
                'launch' => $status['launch'],
            ],
            'revision' => $status['revision'],
            'version' => $status['version'],
            'rollout' => [
                'state' => $status['rolloutState'],
                'reason' => $status['rolloutReason'],
            ],
            'scaling' => $status['scaling'],
            'cpuTarget' => $status['cpuTarget'],
            'load' => $status['load'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function jsonEnvStatuses(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'app' => $row['app'],
            'exists' => (bool) $row['exists'],
            'tasks' => [
                'running' => (int) $row['running'],
                'desired' => (int) $row['desired'],
                'pending' => (int) $row['pending'],
            ],
            'revision' => $row['revision'],
            'version' => $row['version'],
            'rollout' => [
                'state' => $row['rolloutState'],
                'reason' => $row['rolloutReason'],
            ],
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $service
     */
    public static function launchType(array $service): string
    {
        if (($service['launchType'] ?? null) === 'FARGATE') {
            return 'FARGATE';
        }

        foreach ($service['capacityProviderStrategy'] ?? [] as $strategy) {
            if (($strategy['capacityProvider'] ?? null) === 'FARGATE_SPOT') {
                return 'SPOT';
            }
        }

        return 'FARGATE';
    }

    /**
     * `yolo-prod-app-web:42` → `web:42`.
     */
    public static function revisionLabel(?string $taskDefinitionArn): ?string
    {
        if ($taskDefinitionArn === null || $taskDefinitionArn === '') {
            return null;
        }

        $family = substr($taskDefinitionArn, (int) strrpos($taskDefinitionArn, '/') + 1);

        if (! str_contains($family, ':')) {
            return $family;
        }

        [$name, $revision] = explode(':', $family, 2);
        $group = substr($name, (int) strrpos($name, '-') + 1);

        return "{$group}:{$revision}";
    }

    /**
     * A digest reference (`repo@sha256:…`) has no human version, so null.
     */
    public static function versionFromImage(string $image): ?string
    {
        if ($image === '' || str_contains($image, '@')) {
            return null;
        }

        $colon = strrpos($image, ':');

        // The only colon may be a registry host:port — still an untagged reference.
        if ($colon === false || str_contains(substr($image, $colon), '/')) {
            return null;
        }

        return substr($image, $colon + 1);
    }

    public static function formatSpec(?string $cpu, ?string $memory, string $launch): string
    {
        if ($cpu === null || $memory === null) {
            return '—';
        }

        $vcpu = rtrim(rtrim(number_format((int) $cpu / 1024, 2), '0'), '.');
        $gb = rtrim(rtrim(number_format((int) $memory / 1024, 2), '0'), '.');

        return sprintf('%s vCPU · %s GB · %s', $vcpu, $gb, $launch);
    }

    public static function formatTasks(int $running, int $desired, int $pending): string
    {
        $label = sprintf('%d/%d', $running, $desired);

        if ($desired === 0) {
            return sprintf('<fg=gray>%s</>', $label);
        }

        if ($running >= $desired) {
            return sprintf('<fg=green>%s</>', $label);
        }

        if ($running === 0) {
            return sprintf('<fg=red>%s</>', $label);
        }

        return sprintf('<fg=yellow>%s</>', $label);
    }

    /**
     * @param  array{min: int, max: int, policies: array<int, array{metric: string, target: float}>}|null  $scaling
     */
    public static function formatScaling(?array $scaling, ServerGroup $group): string
    {
        if ($scaling === null) {
            return $group->isSingleton() ? 'singleton' : 'fixed';
        }

        $bounds = sprintf('%d–%d auto', $scaling['min'], $scaling['max']);

        $policies = array_map(static::policyLabel(...), $scaling['policies']);

        return $policies === [] ? $bounds : sprintf('%s (%s)', $bounds, implode(', ', $policies));
    }

    /**
     * @param  array{metric: string, target: float}  $policy
     */
    protected static function policyLabel(array $policy): string
    {
        return match ($policy['metric']) {
            'ECSServiceAverageCPUUtilization' => sprintf('cpu %s%%', static::trimFloat($policy['target'])),
            'concurrency' => sprintf('concurrency %s', static::trimFloat($policy['target'])),
            'burst' => 'burst',
            'backlog' => 'backlog',
            default => $policy['metric'],
        };
    }

    /**
     * @param  array{cpu: ?float, memory: ?float, requests: ?float, response: ?float, series?: array<string, array<int, float>>}  $load
     */
    public static function formatLoad(array $load, ?float $cpuTarget, ServerGroup $group): string
    {
        $cpu = $load['cpu'] === null
            ? 'cpu —'
            : ($cpuTarget === null
                ? sprintf('cpu %s%%', static::trimFloat($load['cpu']))
                : sprintf('cpu %s%%/%s%%', static::trimFloat($load['cpu']), static::trimFloat($cpuTarget)));

        $cpu .= static::sparkSuffix($load['series']['cpu'] ?? []);

        $memory = $load['memory'] === null
            ? 'mem —'
            : sprintf('mem %s%%', static::trimFloat($load['memory'])) . static::sparkSuffix($load['series']['memory'] ?? []);

        $parts = [$cpu, $memory];

        if ($group === ServerGroup::WEB) {
            if ($load['requests'] !== null) {
                $parts[] = sprintf('%s rpm', static::trimFloat($load['requests'])) . static::sparkSuffix($load['series']['requests'] ?? []);
            }

            if ($load['response'] !== null) {
                $parts[] = sprintf('%d ms', (int) round($load['response'] * 1000));
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<int, float>  $series
     */
    protected static function sparkSuffix(array $series): string
    {
        $spark = static::sparkline($series);

        return $spark === '' ? '' : sprintf(' <fg=gray>%s</>', $spark);
    }

    /**
     * Two datapoints per character, so a 15-point series fits in ~8 characters
     * beside the other load metrics.
     *
     * @param  array<int, float>  $series
     */
    public static function sparkline(array $series): string
    {
        if ($series === []) {
            return '';
        }

        $min = min($series);
        $max = max($series);

        // Chart::plot blanks a zero-range series; nudge the ceiling so a flat series
        // draws a baseline instead.
        return Chart::plot($series, (int) ceil(count($series) / 2), 1, $min, $max === $min ? $min + 1 : $max)[0];
    }

    /**
     * Backlog alone can't say "healthy" without throughput, so it's reported, not alarmed.
     */
    public static function formatBacklog(int $backlog): string
    {
        return $backlog === 0
            ? '<fg=gray>empty</>'
            : sprintf('%s pending', number_format($backlog));
    }

    public static function progressBar(int $running, int $desired, int $width = 12): string
    {
        $ratio = $desired > 0 ? min(1.0, $running / $desired) : 1.0;
        $filled = (int) round($ratio * $width);

        return str_repeat('█', $filled) . str_repeat('░', max(0, $width - $filled));
    }

    public static function formatRolloutState(?string $state): string
    {
        return match ($state) {
            'IN_PROGRESS' => '<fg=blue>IN PROGRESS</>',
            'COMPLETED' => '<fg=green>COMPLETED</>',
            'FAILED' => '<fg=red>FAILED</>',
            null => '<fg=gray>—</>',
            default => $state,
        };
    }

    /**
     * @param  array<string, mixed>  $deployment
     */
    public static function runningTime(array $deployment, int $now): int
    {
        $created = static::timestamp($deployment['createdAt'] ?? null);

        if ($created === null) {
            return 0;
        }

        if (($deployment['rolloutState'] ?? null) === 'IN_PROGRESS') {
            return max(0, $now - $created);
        }

        $updated = static::timestamp($deployment['updatedAt'] ?? null) ?? $now;

        return max(0, $updated - $created);
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, array<string, mixed>>
     */
    public static function inProgressDeployments(array $statuses): array
    {
        return array_values(array_filter(
            $statuses,
            fn (array $status): bool => ($status['rolloutState'] ?? null) === 'IN_PROGRESS',
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public static function anyDeploymentFailed(array $statuses): bool
    {
        foreach ($statuses as $status) {
            if (($status['rolloutState'] ?? null) === 'FAILED') {
                return true;
            }
        }

        return false;
    }

    protected static function trimFloat(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    /**
     * AWS timestamps arrive as the SDK's DateTimeResult, not scalars.
     */
    protected static function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return (int) strtotime($value) ?: null;
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     * @param  array<int, array{label: string, name: string, backlog: int}>  $queues
     * @return array<int, string>
     */
    protected function statusLines(array $statuses, int $now, bool $deployments = true, bool $load = true, array $queues = []): array
    {
        $lines = [];

        if ($deployments) {
            $lines = [...$lines, ...$this->deploymentLines($statuses, $now)];
        }

        $lines = [...$lines, ...$this->summaryLines($statuses)];

        if ($load) {
            $lines = [...$lines, ...$this->loadLines($statuses)];
        }

        $lines = [...$lines, ...$this->queueLines($queues)];

        return [...$lines, ...$this->dashboardLink()];
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, string>
     */
    protected function deploymentLines(array $statuses, int $now): array
    {
        $rolling = static::inProgressDeployments($statuses);

        if ($rolling === []) {
            return [];
        }

        $lines = ['  <options=bold>Deployment in progress</>', ''];

        foreach ($rolling as $status) {
            $deployment = $status['primary'] ?? [];
            $running = (int) ($deployment['runningCount'] ?? 0);
            $desired = (int) ($deployment['desiredCount'] ?? 0);

            $lines[] = sprintf(
                '  %-10s <fg=cyan>%s</> %d/%d · %s · %s · %s',
                $status['group']->value,
                static::progressBar($running, $desired),
                $running,
                $desired,
                static::formatRolloutState($status['rolloutState'] ?? null),
                $status['revision'] ?? '—',
                Helpers::humaniseElapsed(static::runningTime($deployment, $now)),
            );

            if (($status['rolloutState'] ?? null) === 'FAILED' && ! empty($status['rolloutReason'])) {
                $lines[] = sprintf('             <fg=red>%s</>', $status['rolloutReason']);
            }
        }

        return [...$lines, ''];
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, string>
     */
    protected function summaryLines(array $statuses): array
    {
        $buffer = new BufferedOutput($this->output->getVerbosity(), $this->output->isDecorated(), clone $this->output->getFormatter());

        $table = new Table($buffer);
        $table->setHeaders(['Group', 'Spec', 'Tasks', 'Scaling', 'Version']);

        foreach ($statuses as $status) {
            $table->addRow($this->summaryRow($status));
        }

        $table->render();

        return explode("\n", rtrim($buffer->fetch(), "\n"));
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<int, string>
     */
    protected function summaryRow(array $status): array
    {
        if (! $status['exists']) {
            return [$status['group']->value, '<fg=gray>not deployed</>', '<fg=gray>—</>', '<fg=gray>—</>', '<fg=gray>—</>'];
        }

        $version = $status['version'] === null
            ? ($status['revision'] ?? '—')
            : sprintf('%s · %s', $status['revision'] ?? '—', $status['version']);

        return [
            $status['group']->value,
            static::formatSpec($status['cpu'], $status['memory'], $status['launch']),
            static::formatTasks($status['running'], $status['desired'], $status['pending']),
            static::formatScaling($status['scaling'], $status['group']),
            $version,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    protected function envRollupLines(array $rows): array
    {
        $buffer = new BufferedOutput($this->output->getVerbosity(), $this->output->isDecorated(), clone $this->output->getFormatter());

        $table = new Table($buffer);
        $table->setHeaders(['App', 'Web', 'Rollout', 'Version']);

        foreach ($rows as $row) {
            $table->addRow($this->envRollupRow($row));
        }

        $table->render();

        return explode("\n", rtrim($buffer->fetch(), "\n"));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    protected function envRollupRow(array $row): array
    {
        if (! $row['exists']) {
            return [$row['app'], '<fg=gray>—</>', '<fg=gray>not deployed</>', '<fg=gray>—</>'];
        }

        $version = $row['version'] === null
            ? ($row['revision'] ?? '—')
            : sprintf('%s · %s', $row['revision'] ?? '—', $row['version']);

        return [
            $row['app'],
            static::formatTasks($row['running'], $row['desired'], $row['pending']),
            static::formatRolloutState($row['rolloutState']),
            $version,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, string>
     */
    protected function loadLines(array $statuses): array
    {
        $live = array_values(array_filter($statuses, fn (array $status): bool => (bool) $status['exists']));

        if ($live === []) {
            return [];
        }

        $lines = ['', '  <options=bold>Load</> <fg=gray>(last 15 min)</>'];

        foreach ($live as $status) {
            $lines[] = sprintf(
                '  %-10s %s',
                $status['group']->value,
                static::formatLoad($status['load'], $status['cpuTarget'], $status['group']),
            );
        }

        return $lines;
    }

    /**
     * @param  array<int, array{label: string, name: string, backlog: int}>  $queues
     * @return array<int, string>
     */
    protected function queueLines(array $queues): array
    {
        if ($queues === []) {
            return [];
        }

        $lines = ['', '  <options=bold>Queue</> <fg=gray>(backlog)</>'];

        foreach ($queues as $queue) {
            $lines[] = sprintf('  %-10s %s', $queue['label'], static::formatBacklog($queue['backlog']));
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    protected function dashboardLink(): array
    {
        $url = (new Dashboard())->consoleUrl();

        if ($url === null) {
            return [];
        }

        return ['', sprintf('  <options=bold>Dashboard</> <href=%s>%s</>', $url, $url)];
    }
}
