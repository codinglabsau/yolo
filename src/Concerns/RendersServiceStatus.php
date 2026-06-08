<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
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
 * Builds the live `yolo status` picture for each of an app's service groups and
 * renders it as display lines. Shared by StatusCommand (the full polling
 * dashboard) and DeployCommand (the one-shot end-of-deploy recap).
 *
 * Every external read is defensive — a missing service, scalable target or
 * metric yields a null/"—" cell rather than crashing the dashboard, so a
 * half-provisioned or cold app still renders.
 */
trait RendersServiceStatus
{
    /**
     * Gather a status row for every group the app runs. Pure-ish: only live AWS
     * reads (all wrapped), no output. `withLoad` is off for the end-of-deploy
     * recap, which doesn't show load — so it skips the CloudWatch round-trips that
     * would only add latency to every deploy for data it never renders.
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
            'load' => ['cpu' => null, 'memory' => null, 'requests' => null, 'response' => null],
            'cpuTarget' => null,
        ];

        try {
            $service = Ecs::service($cluster, $serviceName);
        } catch (ResourceDoesNotExistException) {
            return $row;
        }

        $row['exists'] = true;
        $row['running'] = (int) ($service['runningCount'] ?? 0);
        $row['desired'] = (int) ($service['desiredCount'] ?? 0);
        $row['pending'] = (int) ($service['pendingCount'] ?? 0);
        $row['launch'] = static::launchType($service);

        $primary = collect($service['deployments'] ?? [])->firstWhere('status', 'PRIMARY');

        if ($primary !== null) {
            $taskDefinitionArn = $primary['taskDefinition'] ?? null;

            $row['primary'] = $primary;
            $row['rolloutState'] = $primary['rolloutState'] ?? null;
            $row['rolloutReason'] = $primary['rolloutStateReason'] ?? null;
            $row['revision'] = static::revisionLabel($taskDefinitionArn);

            try {
                $taskDefinition = $taskDefinitionArn === null ? [] : Ecs::taskDefinition($taskDefinitionArn);
                $row['cpu'] = $taskDefinition['cpu'] ?? null;
                $row['memory'] = $taskDefinition['memory'] ?? null;
                $row['version'] = static::versionFromImage($taskDefinition['containerDefinitions'][0]['image'] ?? '');
            } catch (ResourceDoesNotExistException) {
                // leave the task-def-derived fields null
            }
        }

        $row['scaling'] = static::gatherScaling($group);
        $row['cpuTarget'] = static::cpuTargetFrom($row['scaling']);

        if ($withLoad) {
            $row['load'] = static::gatherLoad($group, $cluster, $serviceName);
        }

        return $row;
    }

    /**
     * The live scalable target bounds + a compact view of each scaling policy, or
     * null when the group has no scalable target (a fixed-count service).
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

        return [...$bounds, 'policies' => $policies];
    }

    /**
     * Reduce a scaling policy to its metric + target value. Target-tracking
     * policies carry a PredefinedMetricType; a step-scaling queue-backlog policy
     * has no target value, so it's surfaced as a metric-only entry.
     *
     * @param  array<string, mixed>  $policy
     * @return array{metric: string, target: float}|null
     */
    protected static function policyView(array $policy): ?array
    {
        $config = $policy['TargetTrackingScalingPolicyConfiguration'] ?? null;

        if ($config === null) {
            // Step scaling (the queue scale-to-zero/backlog policy) — name it but
            // there's no single target value to show.
            return ['metric' => 'backlog', 'target' => 0.0];
        }

        $metric = $config['PredefinedMetricSpecification']['PredefinedMetricType'] ?? 'custom';

        return ['metric' => $metric, 'target' => (float) ($config['TargetValue'] ?? 0)];
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
     * Current load for a service: ECS CPU/memory utilisation (avg, last 5 min)
     * and — for the web service only — ALB request rate and response time.
     *
     * @return array{cpu: ?float, memory: ?float, requests: ?float, response: ?float}
     */
    protected static function gatherLoad(ServerGroup $group, string $cluster, string $serviceName): array
    {
        $dimensions = [
            ['Name' => 'ClusterName', 'Value' => $cluster],
            ['Name' => 'ServiceName', 'Value' => $serviceName],
        ];

        $load = [
            'cpu' => CloudWatch::metricStatistic('AWS/ECS', 'CPUUtilization', $dimensions, 'Average'),
            'memory' => CloudWatch::metricStatistic('AWS/ECS', 'MemoryUtilization', $dimensions, 'Average'),
            'requests' => null,
            'response' => null,
        ];

        if ($group !== ServerGroup::WEB) {
            return $load;
        }

        $targetGroup = static::targetGroupDimension();

        if ($targetGroup !== null) {
            $albDimensions = [['Name' => 'TargetGroup', 'Value' => $targetGroup]];
            // Request count per target over the last minute → a current per-minute rate.
            $load['requests'] = CloudWatch::metricStatistic('AWS/ApplicationELB', 'RequestCountPerTarget', $albDimensions, 'Sum', 60, 300);
            $load['response'] = CloudWatch::metricStatistic('AWS/ApplicationELB', 'TargetResponseTime', $albDimensions, 'Average', 60, 300);
        }

        return $load;
    }

    /**
     * The `targetgroup/{name}/{id}` dimension value for the web target group, or
     * null when the app has none (headless, or not yet synced).
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

    // --- Pure formatters (unit-tested directly) -----------------------------

    /**
     * FARGATE / SPOT from a service's launch type or capacity-provider strategy.
     *
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
     * `yolo-prod-app-web:42` → `web:42`. The task-definition ARN's resource part
     * is `task-definition/{family}:{revision}`; we keep the group + revision.
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
     * The deployed app version, parsed from the container image tag. A digest
     * reference (`repo@sha256:…`) has no human version, so it returns null.
     */
    public static function versionFromImage(string $image): ?string
    {
        if ($image === '' || str_contains($image, '@')) {
            return null;
        }

        $colon = strrpos($image, ':');

        // No colon, or the only colon is a registry host:port (no '/' after it) →
        // an untagged reference, so no version to show.
        if ($colon === false || str_contains(substr($image, $colon), '/')) {
            return null;
        }

        return substr($image, $colon + 1);
    }

    /**
     * `512` CPU units / `1024` MiB → `0.5 vCPU · 1 GB`. Null spec → "—".
     */
    public static function formatSpec(?string $cpu, ?string $memory, string $launch): string
    {
        if ($cpu === null || $memory === null) {
            return '—';
        }

        $vcpu = rtrim(rtrim(number_format((int) $cpu / 1024, 2), '0'), '.');
        $gb = rtrim(rtrim(number_format((int) $memory / 1024, 2), '0'), '.');

        return sprintf('%s vCPU · %s GB · %s', $vcpu, $gb, $launch);
    }

    /**
     * `2/2` running/desired, coloured: green when at/above desired, red when zero
     * but wanted, yellow while converging, gray when deliberately at zero.
     */
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
     * `1–4 auto (cpu 65%, req 1200)`, or `fixed` / `singleton` when there's no
     * scalable target.
     *
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
            'ALBRequestCountPerTarget' => sprintf('req %s', static::trimFloat($policy['target'])),
            'backlog' => 'backlog',
            default => $policy['metric'],
        };
    }

    /**
     * `cpu 43%/65% · mem 38% · 410 rpm · 126 ms`. Missing metrics render "—";
     * request rate / response time only apply to the web service.
     *
     * @param  array{cpu: ?float, memory: ?float, requests: ?float, response: ?float}  $load
     */
    public static function formatLoad(array $load, ?float $cpuTarget, ServerGroup $group): string
    {
        $cpu = $load['cpu'] === null
            ? 'cpu —'
            : ($cpuTarget === null
                ? sprintf('cpu %s%%', static::trimFloat($load['cpu']))
                : sprintf('cpu %s%%/%s%%', static::trimFloat($load['cpu']), static::trimFloat($cpuTarget)));

        $parts = [$cpu, $load['memory'] === null ? 'mem —' : sprintf('mem %s%%', static::trimFloat($load['memory']))];

        if ($group === ServerGroup::WEB) {
            if ($load['requests'] !== null) {
                $parts[] = sprintf('%s rpm', static::trimFloat($load['requests']));
            }

            if ($load['response'] !== null) {
                $parts[] = sprintf('%d ms', (int) round($load['response'] * 1000));
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * A `███████░░░░░` bar of the new revision's running/desired ratio.
     */
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
     * Seconds a deployment has been running: now − created while in progress, or
     * its completed span (updated − created) once settled.
     *
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
     * The status rows whose primary deployment is mid-rollout.
     *
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
     * Any deployment failed — used for the snapshot exit code.
     *
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
     * AWS timestamps come back as DateTimeInterface (the SDK's DateTimeResult);
     * normalise to a unix int, tolerating an int/string too.
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

    // --- Rendering (instance — uses $this->output) --------------------------

    /**
     * The full set of display lines for a status frame. `deployments` puts the
     * in-progress rollout bars on top; `load` adds the per-group load panel. The
     * end-of-deploy recap turns both off and shows just the summary + link.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, string>
     */
    protected function statusLines(array $statuses, int $now, bool $deployments = true, bool $load = true): array
    {
        $lines = [];

        if ($deployments) {
            $lines = [...$lines, ...$this->deploymentLines($statuses, $now)];
        }

        $lines = [...$lines, ...$this->summaryLines($statuses)];

        if ($load) {
            $lines = [...$lines, ...$this->loadLines($statuses)];
        }

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
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<int, string>
     */
    protected function loadLines(array $statuses): array
    {
        $live = array_values(array_filter($statuses, fn (array $status) => $status['exists']));

        if ($live === []) {
            return [];
        }

        $lines = ['', '  <options=bold>Load</> <fg=gray>(last 5 min)</>'];

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
