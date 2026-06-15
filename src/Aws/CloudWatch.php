<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class CloudWatch
{
    public static function alarm(string $name): array
    {
        foreach (Aws::cloudWatch()->describeAlarms()['MetricAlarms'] ?? [] as $alarm) {
            if ($alarm['AlarmName'] === $name) {
                return $alarm;
            }
        }

        throw new ResourceDoesNotExistException("Could not find CloudWatch alarm $name");
    }

    /**
     * The decoded body of a dashboard, addressed by name. Returns the parsed
     * `{ "widgets": [...] }` document so callers diff an array, not a JSON string.
     * Translates the SDK's not-found error to the project's standard signal.
     *
     * @return array<string, mixed>
     */
    public static function dashboard(string $name): array
    {
        try {
            $body = Aws::cloudWatch()->getDashboard(['DashboardName' => $name])['DashboardBody'];
        } catch (AwsException) {
            throw new ResourceDoesNotExistException("Could not find CloudWatch dashboard $name");
        }

        return json_decode((string) $body, true);
    }

    /**
     * The ordered series of datapoints for one metric/statistic over a lookback
     * window, oldest → newest — used by `yolo status` to read current load (ECS
     * CPU/memory, ALB request rate / response time): the last value is the live
     * reading, the whole series renders a sparkline and gives the `/yolo` skill a
     * trend rather than a lone number. Missing periods are dropped, so the array
     * holds only real datapoints. Empty when the metric has no data in the window
     * (a brand-new or idle service) or the read fails, so the caller degrades to a
     * "—" / no sparkline rather than a hard error.
     *
     * @param  array<int, array{Name: string, Value: string}>  $dimensions
     * @return array<int, float>
     */
    public static function metricSeries(string $namespace, string $metric, array $dimensions, string $stat, int $period = 60, int $lookback = 300): array
    {
        try {
            $datapoints = Aws::cloudWatch()->getMetricStatistics([
                'Namespace' => $namespace,
                'MetricName' => $metric,
                'Dimensions' => $dimensions,
                'StartTime' => gmdate('Y-m-d\TH:i:s\Z', time() - $lookback),
                'EndTime' => gmdate('Y-m-d\TH:i:s\Z', time()),
                'Period' => $period,
                'Statistics' => [$stat],
            ])['Datapoints'] ?? [];
        } catch (AwsException) {
            return [];
        }

        usort($datapoints, fn (array $a, array $b): int => $a['Timestamp'] <=> $b['Timestamp']);

        return array_values(array_filter(
            array_map(fn (array $point): ?float => isset($point[$stat]) ? (float) $point[$stat] : null, $datapoints),
            fn (?float $value): bool => $value !== null,
        ));
    }
}
