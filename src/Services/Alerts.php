<?php

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Aws\Rds;

/**
 * The single source of truth for the alert-alarm thresholds — the sync steps
 * provision alarms from these values and the CloudWatch dashboard draws its
 * red alarm lines from the same ones, so the lines on the charts can never
 * disagree with the alarms that page.
 */
final class Alerts
{
    /** Web target 5xx as a percentage of the app's own requests. */
    public const int WEB_5XX_RATE_PERCENT = 5;

    /** ALB-generated 5xx per 5-minute period. */
    public const int ALB_5XX_PER_FIVE_MINUTES = 25;

    public const int VALKEY_MEMORY_PERCENT = 85;

    /** Evictions per 5-minute period — sustained rate, not any-eviction. */
    public const int VALKEY_EVICTIONS_PER_FIVE_MINUTES = 100;

    public const int DATABASE_CPU_PERCENT = 80;

    /** Freeable-memory floor as a fraction of the writer class's memory. */
    public const float DATABASE_MEMORY_FLOOR_FRACTION = 0.05;

    /** Connection ceiling as a fraction of the class default max_connections. */
    public const float DATABASE_CONNECTIONS_FRACTION = 0.75;

    public const int DATABASE_BUFFER_CACHE_PERCENT = 85;

    /**
     * Aurora MySQL capacity by instance class: [memory GiB, default
     * max_connections]. The memory floor and connection ceiling need absolute
     * values, and CloudWatch has no notion of an instance's totals — so they
     * derive from the writer's class at sync time. max_connections rows follow
     * Aurora MySQL's default GREATEST(log2(mem/805306368)*45,
     * log2(mem/8187281408)*1000) — derive new rows from the formula, don't
     * guess (the ~1000 tier only starts at 16 GiB).
     *
     * @var array<string, array{0: float, 1: int}>
     */
    public const array AURORA_CLASSES = [
        'db.t3.small' => [2, 45],
        'db.t3.medium' => [4, 90],
        'db.t4g.medium' => [4, 90],
        'db.t4g.large' => [8, 150],
        'db.r5.large' => [16, 1000],
        'db.r5.xlarge' => [32, 2000],
        'db.r5.2xlarge' => [64, 3000],
        'db.r5.4xlarge' => [128, 4000],
        'db.r6g.large' => [16, 1000],
        'db.r6g.xlarge' => [32, 2000],
        'db.r6g.2xlarge' => [64, 3000],
        'db.r6g.4xlarge' => [128, 4000],
        'db.r6i.large' => [16, 1000],
        'db.r6i.xlarge' => [32, 2000],
        'db.r6i.2xlarge' => [64, 3000],
        'db.r6i.4xlarge' => [128, 4000],
        'db.r7g.large' => [16, 1000],
        'db.r7g.xlarge' => [32, 2000],
        'db.r7g.2xlarge' => [64, 3000],
        'db.r7g.4xlarge' => [128, 4000],
    ];

    /**
     * The writer instance's class, from the live cluster membership — null
     * while the writer isn't resolvable (a cluster mid-creation or mid-
     * failover), which on an adopted database means "not yet", not an error.
     */
    public static function writerClass(string $cluster): ?string
    {
        $members = Rds::cluster($cluster)['DBClusterMembers'] ?? [];
        $writer = collect($members)->firstWhere('IsClusterWriter', true)['DBInstanceIdentifier'] ?? null;

        if ($writer === null) {
            return null;
        }

        return collect(Rds::clusterInstances($cluster))
            ->firstWhere('DBInstanceIdentifier', $writer)['DBInstanceClass'] ?? null;
    }

    /** The memory-floor alarm threshold in bytes for a tabulated class. */
    public static function databaseMemoryFloorBytes(string $writerClass): float
    {
        return round(self::AURORA_CLASSES[$writerClass][0] * self::DATABASE_MEMORY_FLOOR_FRACTION * 1024 ** 3);
    }

    /** The connection-ceiling alarm threshold for a tabulated class. */
    public static function databaseConnectionsCeiling(string $writerClass): float
    {
        return round(self::AURORA_CLASSES[$writerClass][1] * self::DATABASE_CONNECTIONS_FRACTION);
    }
}
