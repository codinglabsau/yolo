<?php

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;

/**
 * The queues whose visible-message count is the queue tier's backlog signal, keyed by
 * the CloudWatch metric-math id each one is read under. {@see QueueBacklogPolicy} and
 * {@see QueueScaleToZeroBootstrap} both scale on this set, so the policy and the 0→1
 * alarm can never watch different queues.
 *
 * Only the default tier counts: a `high` tier is meant to stay near-empty, so the base
 * backlog is the throughput signal. Under shared isolation that is the app's one default
 * queue; under dedicated isolation the tier drains a landlord queue plus one per tenant,
 * and the backlog is the SUM across all of them. The ids derive from the scope name, so
 * adding a tenant appends a term without renumbering the rest.
 */
final class QueueBacklog
{
    /**
     * @return array<string, string> metric id => queue name
     */
    public static function queues(): array
    {
        if (! Manifest::fansQueuesPerTenant()) {
            return ['visible' => Helpers::defaultQueueName()];
        }

        $queues = [];

        // Raw tenant read: tenants() derives each apex via Route 53, a round-trip a
        // plan-pass metric build must not need.
        foreach (['landlord', ...array_keys(Manifest::get('multitenancy.tenants'))] as $scope) {
            $queues[self::metricId($scope)] = Helpers::defaultQueueName($scope);
        }

        return $queues;
    }

    /**
     * The visible-messages term to divide by running tasks: one id, or a SUM over the set.
     */
    public static function expression(): string
    {
        $ids = array_keys(self::queues());

        return count($ids) === 1
            ? $ids[0]
            : sprintf('SUM([%s])', implode(', ', $ids));
    }

    /**
     * One MetricStat per queue, in the shape both a scaling policy's
     * CustomizedMetricSpecification and a metric-math alarm accept.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function metricStats(string $stat, ?int $period = null): array
    {
        $stats = [];

        foreach (self::queues() as $id => $queueName) {
            $stats[] = [
                'Id' => $id,
                'MetricStat' => array_filter([
                    'Metric' => [
                        'Namespace' => 'AWS/SQS',
                        'MetricName' => 'ApproximateNumberOfMessagesVisible',
                        'Dimensions' => [['Name' => 'QueueName', 'Value' => $queueName]],
                    ],
                    'Period' => $period,
                    'Stat' => $stat,
                ]),
                'ReturnData' => false,
            ];
        }

        return $stats;
    }

    /**
     * @return array<int, string>
     */
    public static function queueNames(): array
    {
        $names = array_values(self::queues());
        sort($names);

        return $names;
    }

    /**
     * The queue names a live policy configuration or alarm actually reads — from its
     * metric-math `Metrics` list, or the single-metric `Dimensions` form.
     *
     * @param  array<string, mixed>  $live
     * @return array<int, string>
     */
    public static function liveQueueNames(array $live): array
    {
        $names = [];

        foreach ($live['Metrics'] ?? [] as $metric) {
            foreach ($metric['MetricStat']['Metric']['Dimensions'] ?? [] as $dimension) {
                if ($dimension['Name'] === 'QueueName') {
                    $names[] = $dimension['Value'];
                }
            }
        }

        foreach ($live['Dimensions'] ?? [] as $dimension) {
            if ($dimension['Name'] === 'QueueName') {
                $names[] = $dimension['Value'];
            }
        }

        sort($names);

        return $names;
    }

    /** Metric-math ids must match ^[a-z][a-zA-Z0-9_]*$; a tenant id can carry dashes. */
    private static function metricId(string $scope): string
    {
        return 'visible_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($scope));
    }
}
