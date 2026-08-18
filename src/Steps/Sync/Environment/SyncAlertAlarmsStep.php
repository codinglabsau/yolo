<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Services\Alerts;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Resources\CloudWatch\AlertAlarm;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The environment's "bad things are happening" alarms on the env SNS topic —
 * the signals that mean a human should look, kept deliberately separate from
 * the autoscaling alarms whose ALARM state is part of their control loop:
 *
 * - ALB-generated 5xx: the load balancer itself can't route (no healthy
 *   targets, a dead target group) — app-side errors have their own per-app
 *   rate alarm in sync:app.
 * - Valkey memory/evictions: the shared cache/session store under pressure —
 *   evictions mean sessions are being thrown away before anyone notices.
 * - Aurora CPU / memory / connections / buffer-cache (only when the manifest
 *   declares a `database`): saturation tells on the adopted cluster, watching
 *   the writer so the alarm follows a failover.
 *
 * Thresholds live in {@see Alerts} — the CloudWatch dashboard draws its alarm
 * lines from the same values, so charts and alarms can't drift apart.
 */
class SyncAlertAlarmsStep implements Step
{
    use SynchronisesResource;

    /**
     * The full alert family this step can provision — the teardown step
     * consumes the same list, so the two can never diverge and orphan an
     * alarm past its topic.
     *
     * @var array<int, string>
     */
    public const array SUFFIXES = [
        'alb-5xx',
        'valkey-memory',
        'valkey-evictions',
        'database-cpu',
        'database-memory',
        'database-connections',
        'database-buffer-cache',
    ];

    public function __invoke(array $options): StepResult
    {
        $results = [];

        foreach ([...$this->albAlarms(), ...$this->valkeyAlarms(), ...$this->auroraAlarms()] as $alarm) {
            $results[] = $this->syncResource($alarm, $options);
        }

        return $this->aggregateResults($results);
    }

    /**
     * The ALB's own 5xx — needs the load balancer to exist (its CloudWatch
     * dimension is an ARN suffix), so on a greenfield plan it reports pending
     * and lands on the next sync.
     *
     * @return array<int, AlertAlarm>
     */
    protected function albAlarms(): array
    {
        try {
            $dimensions = [
                ['Name' => 'LoadBalancer', 'Value' => Dashboard::loadBalancerDimension((new LoadBalancer())->arn())],
            ];
        } catch (ResourceDoesNotExistException) {
            $this->recordChange(Change::make('alb 5xx alert', null, 'created (load balancer pending)'));

            return [];
        }

        return [
            new AlertAlarm(
                suffix: 'alb-5xx',
                description: 'The load balancer is generating 5xx responses itself - no healthy targets, a dead target group, or a broken rule; app-side errors have their own per-app alarm',
                alarmScope: Scope::Env,
                comparisonOperator: 'GreaterThanOrEqualToThreshold',
                threshold: Alerts::ALB_5XX_PER_FIVE_MINUTES,
                evaluationPeriods: 2,
                namespace: 'AWS/ApplicationELB',
                metricName: 'HTTPCode_ELB_5XX_Count',
                dimensions: $dimensions,
                statistic: 'Sum',
            ),
        ];
    }

    /**
     * @return array<int, AlertAlarm>
     */
    protected function valkeyAlarms(): array
    {
        // Single-node replication group: the node's CacheClusterId is the
        // group id with the -001 member suffix.
        $dimensions = [
            ['Name' => 'CacheClusterId', 'Value' => (new CacheCluster())->name() . '-001'],
        ];

        return [
            new AlertAlarm(
                suffix: 'valkey-memory',
                description: 'The shared Valkey node is above 85% memory - cache/session pressure; evictions follow if this holds',
                alarmScope: Scope::Env,
                comparisonOperator: 'GreaterThanOrEqualToThreshold',
                threshold: Alerts::VALKEY_MEMORY_PERCENT,
                evaluationPeriods: 2,
                namespace: 'AWS/ElastiCache',
                metricName: 'DatabaseMemoryUsagePercentage',
                dimensions: $dimensions,
            ),
            // A sustained rate, not any-eviction: the node runs allkeys-lru by
            // design, so background LRU churn on a full-but-healthy cache is
            // normal — a >0 trigger would latch red permanently the day an
            // env's working set fills the node. Heavy sustained eviction is
            // the session-loss signal.
            new AlertAlarm(
                suffix: 'valkey-evictions',
                description: 'Valkey is evicting heavily and sustained - sessions and cache entries are being thrown away; the node needs headroom',
                alarmScope: Scope::Env,
                comparisonOperator: 'GreaterThanOrEqualToThreshold',
                threshold: Alerts::VALKEY_EVICTIONS_PER_FIVE_MINUTES,
                evaluationPeriods: 3,
                namespace: 'AWS/ElastiCache',
                metricName: 'Evictions',
                dimensions: $dimensions,
                statistic: 'Sum',
                datapointsToAlarm: 2,
            ),
        ];
    }

    /**
     * Saturation tells on the declared database cluster, dimensioned on the
     * writer so the alarms follow a failover. Aurora clusters only — a plain
     * RDS instance database has neither the WRITER role dimension nor the
     * BufferCacheHitRatio metric, so the set doesn't exist there (the docs
     * say so). The absolute thresholds (memory floor, connection ceiling)
     * derive from the writer's instance class; a cluster whose writer can't
     * be resolved yet reports pending rather than failing the plan, and a
     * declared-but-nonexistent database keeps Rds::target()'s manifest-error
     * throw, same as every other consumer.
     *
     * @return array<int, AlertAlarm>
     */
    protected function auroraAlarms(): array
    {
        $target = Rds::target();

        if ($target === null || ! $target['cluster']) {
            return [];
        }

        $cluster = $target['identifier'];
        $writerClass = Alerts::writerClass($cluster);

        if ($writerClass === null) {
            $this->recordChange(Change::make('database alerts', null, 'created (cluster writer pending)'));

            return [];
        }

        $dimensions = [
            ['Name' => 'DBClusterIdentifier', 'Value' => $cluster],
            ['Name' => 'Role', 'Value' => 'WRITER'],
        ];

        $alarms = [
            new AlertAlarm(
                suffix: 'database-cpu',
                description: 'Database writer CPU sustained above 80% - queries are queuing; find the load before it becomes an outage',
                alarmScope: Scope::Env,
                comparisonOperator: 'GreaterThanOrEqualToThreshold',
                threshold: Alerts::DATABASE_CPU_PERCENT,
                evaluationPeriods: 3,
                namespace: 'AWS/RDS',
                metricName: 'CPUUtilization',
                dimensions: $dimensions,
            ),
            // 6-of-8 rather than a straight run: the ratio legitimately dips
            // while the buffer pool re-warms after a restart or failover, and
            // that window can exceed a straight half-hour on a large working
            // set — the alarm is for sustained degradation, not warm-up.
            new AlertAlarm(
                suffix: 'database-buffer-cache',
                description: 'Database buffer cache hit ratio below 85% sustained - the working set no longer fits in memory, reads are hitting storage',
                alarmScope: Scope::Env,
                comparisonOperator: 'LessThanThreshold',
                threshold: Alerts::DATABASE_BUFFER_CACHE_PERCENT,
                evaluationPeriods: 8,
                namespace: 'AWS/RDS',
                metricName: 'BufferCacheHitRatio',
                dimensions: $dimensions,
                datapointsToAlarm: 6,
            ),
        ];

        // Serverless v2 reports class "db.serverless" — capacity floats with
        // the ACU range, so the static memory/connection thresholds have no
        // basis there; the percentage-based pair above still holds.
        if ($writerClass === 'db.serverless') {
            return $alarms;
        }

        if (! array_key_exists($writerClass, Alerts::AURORA_CLASSES)) {
            throw new IntegrityCheckException(sprintf(
                'Unknown database instance class "%s" for cluster "%s" - add its memory and max_connections to %s::AURORA_CLASSES so the saturation alarms get real thresholds.',
                $writerClass,
                $cluster,
                Alerts::class,
            ));
        }

        return [
            ...$alarms,
            // 5%, not a rounder 10%: Aurora deliberately gives ~75% of class
            // memory to the buffer pool, so a warm, healthy writer routinely
            // idles with under 10% freeable — a higher floor latches in ALARM
            // under normal operation.
            new AlertAlarm(
                suffix: 'database-memory',
                description: 'Database writer freeable memory below 5% of the instance - swap and restart territory',
                alarmScope: Scope::Env,
                comparisonOperator: 'LessThanOrEqualToThreshold',
                threshold: Alerts::databaseMemoryFloorBytes($writerClass),
                evaluationPeriods: 3,
                namespace: 'AWS/RDS',
                metricName: 'FreeableMemory',
                dimensions: $dimensions,
            ),
            new AlertAlarm(
                suffix: 'database-connections',
                description: 'Database connections above 75% of the class default ceiling - the next spike gets refused connections',
                alarmScope: Scope::Env,
                comparisonOperator: 'GreaterThanOrEqualToThreshold',
                threshold: Alerts::databaseConnectionsCeiling($writerClass),
                evaluationPeriods: 2,
                namespace: 'AWS/RDS',
                metricName: 'DatabaseConnections',
                dimensions: $dimensions,
            ),
        ];
    }
}
