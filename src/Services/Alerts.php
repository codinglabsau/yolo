<?php

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Aws\Rds;

/**
 * Both the alarms and the dashboard's red threshold lines read from here, so
 * the charts can never disagree with the alarms that page.
 */
final class Alerts
{
    public const int WEB_5XX_RATE_PERCENT = 5;

    public const int ALB_5XX_PER_FIVE_MINUTES = 25;

    public const int VALKEY_MEMORY_PERCENT = 85;

    /** A sustained rate, not any-eviction. */
    public const int VALKEY_EVICTIONS_PER_FIVE_MINUTES = 100;

    public const int DATABASE_CPU_PERCENT = 80;

    public const float DATABASE_MEMORY_FLOOR_FRACTION = 0.05;

    public const float DATABASE_CONNECTIONS_FRACTION = 0.75;

    public const int DATABASE_BUFFER_CACHE_PERCENT = 85;

    /**
     * [memory GiB, default max_connections] per Aurora MySQL class — CloudWatch
     * has no notion of an instance's totals, so absolute thresholds derive from
     * the writer's class. max_connections follows Aurora's default
     * GREATEST(log2(mem/805306368)*45, log2(mem/8187281408)*1000); derive new
     * rows from the formula, don't guess (the ~1000 tier only starts at 16 GiB).
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

    /** Null while the writer isn't resolvable (mid-creation or mid-failover) — "not yet", not an error. */
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

    public static function databaseMemoryFloorBytes(string $writerClass): float
    {
        return round(self::AURORA_CLASSES[$writerClass][0] * self::DATABASE_MEMORY_FLOOR_FRACTION * 1024 ** 3);
    }

    public static function databaseConnectionsCeiling(string $writerClass): float
    {
        return round(self::AURORA_CLASSES[$writerClass][1] * self::DATABASE_CONNECTIONS_FRACTION);
    }
}
