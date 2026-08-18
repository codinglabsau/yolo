<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Contracts\Step;
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
 */
class SyncAlertAlarmsStep implements Step
{
    use SynchronisesResource;

    /**
     * Aurora MySQL capacity by instance class: [memory GiB, default
     * max_connections]. The memory floor and connection ceiling alarms need
     * absolute thresholds, and CloudWatch has no notion of an instance's
     * totals — so they derive from the writer's class at sync time. An
     * unknown class hard-fails with a pointer here rather than silently
     * shipping no coverage.
     *
     * @var array<string, array{0: float, 1: int}>
     */
    private const array AURORA_CLASSES = [
        'db.t3.small' => [2, 45],
        'db.t3.medium' => [4, 90],
        'db.t4g.medium' => [4, 90],
        'db.t4g.large' => [8, 1000],
        'db.r5.large' => [16, 1000],
        'db.r5.xlarge' => [32, 2000],
        'db.r5.2xlarge' => [64, 3000],
        'db.r5.4xlarge' => [128, 4000],
        'db.r6g.large' => [16, 1000],
        'db.r6g.xlarge' => [32, 2000],
        'db.r6g.2xlarge' => [64, 3000],
        'db.r6g.4xlarge' => [128, 4000],
        'db.r7g.large' => [16, 1000],
        'db.r7g.xlarge' => [32, 2000],
        'db.r7g.2xlarge' => [64, 3000],
        'db.r7g.4xlarge' => [128, 4000],
    ];

    public function __invoke(array $options): StepResult
    {
        $results = [];

        foreach ([...$this->albAlarms(), ...$this->valkeyAlarms(), ...$this->auroraAlarms()] as $alarm) {
            $results[] = $this->syncResource($alarm, $options);
        }

        return $this->aggregate($results);
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
                threshold: 25,
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
                threshold: 85,
                evaluationPeriods: 2,
                namespace: 'AWS/ElastiCache',
                metricName: 'DatabaseMemoryUsagePercentage',
                dimensions: $dimensions,
            ),
            new AlertAlarm(
                suffix: 'valkey-evictions',
                description: 'Valkey is evicting keys - sessions and cache entries are being thrown away; the node needs headroom',
                alarmScope: Scope::Env,
                comparisonOperator: 'GreaterThanThreshold',
                threshold: 0,
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
     * writer so the alarms follow a failover. The absolute thresholds (memory
     * floor, connection ceiling) derive from the writer's instance class; a
     * cluster that can't be resolved yet reports pending rather than failing
     * the plan.
     *
     * @return array<int, AlertAlarm>
     */
    protected function auroraAlarms(): array
    {
        $cluster = Manifest::database();

        if ($cluster === null) {
            return [];
        }

        $capacity = $this->writerCapacity($cluster);

        if ($capacity === null) {
            $this->recordChange(Change::make('database alerts', null, 'created (cluster pending)'));

            return [];
        }

        [$memoryGib, $maxConnections] = $capacity;

        $dimensions = [
            ['Name' => 'DBClusterIdentifier', 'Value' => $cluster],
            ['Name' => 'Role', 'Value' => 'WRITER'],
        ];

        return [
            new AlertAlarm(
                suffix: 'database-cpu',
                description: 'Database writer CPU sustained above 80% - queries are queuing; find the load before it becomes an outage',
                alarmScope: Scope::Env,
                comparisonOperator: 'GreaterThanOrEqualToThreshold',
                threshold: 80,
                evaluationPeriods: 3,
                namespace: 'AWS/RDS',
                metricName: 'CPUUtilization',
                dimensions: $dimensions,
            ),
            new AlertAlarm(
                suffix: 'database-memory',
                description: 'Database writer freeable memory below 10% of the instance - swap and restart territory',
                alarmScope: Scope::Env,
                comparisonOperator: 'LessThanOrEqualToThreshold',
                threshold: round($memoryGib * 0.10 * 1024 ** 3),
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
                threshold: round($maxConnections * 0.75),
                evaluationPeriods: 2,
                namespace: 'AWS/RDS',
                metricName: 'DatabaseConnections',
                dimensions: $dimensions,
            ),
            new AlertAlarm(
                suffix: 'database-buffer-cache',
                description: 'Database buffer cache hit ratio below 85% for half an hour - the working set no longer fits in memory, reads are hitting storage',
                alarmScope: Scope::Env,
                comparisonOperator: 'LessThanThreshold',
                threshold: 85,
                evaluationPeriods: 6,
                namespace: 'AWS/RDS',
                metricName: 'BufferCacheHitRatio',
                dimensions: $dimensions,
            ),
        ];
    }

    /**
     * The writer instance's [memory GiB, default max_connections], from the
     * live cluster membership — null while the cluster (or its writer) isn't
     * resolvable, which on an adopted database means "not yet", not an error.
     *
     * @return array{0: float, 1: int}|null
     */
    protected function writerCapacity(string $cluster): ?array
    {
        $members = Rds::cluster($cluster)['DBClusterMembers'] ?? [];
        $writer = collect($members)->firstWhere('IsClusterWriter', true)['DBInstanceIdentifier'] ?? null;

        if ($writer === null) {
            return null;
        }

        $class = collect(Rds::clusterInstances($cluster))
            ->firstWhere('DBInstanceIdentifier', $writer)['DBInstanceClass'] ?? null;

        if ($class === null) {
            return null;
        }

        return self::AURORA_CLASSES[$class] ?? throw new IntegrityCheckException(sprintf(
            'Unknown database instance class "%s" for cluster "%s" - add its memory and max_connections to %s::AURORA_CLASSES so the saturation alarms get real thresholds.',
            $class,
            $cluster,
            self::class,
        ));
    }

    /**
     * @param  array<int, StepResult>  $results
     */
    protected function aggregate(array $results): StepResult
    {
        foreach ([
            StepResult::WOULD_CREATE, StepResult::CREATED,
            StepResult::WOULD_SYNC,
        ] as $significant) {
            if (in_array($significant, $results, true)) {
                return $significant;
            }
        }

        return in_array(StepResult::SYNCED, $results, true) ? StepResult::SYNCED : StepResult::SKIPPED;
    }
}
