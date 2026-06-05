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

        return json_decode($body, true);
    }

    /**
     * The single most-recent datapoint for one metric/statistic over a lookback
     * window — used by `yolo status` to read current load (ECS CPU/memory, ALB
     * request rate / response time). Returns null when the metric has no data in
     * the window (a brand-new or idle service) or the read fails, so the caller
     * renders a "—" rather than a hard error.
     *
     * @param  array<int, array{Name: string, Value: string}>  $dimensions
     */
    public static function metricStatistic(string $namespace, string $metric, array $dimensions, string $stat, int $period = 300, int $lookback = 900): ?float
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
            return null;
        }

        if ($datapoints === []) {
            return null;
        }

        usort($datapoints, fn ($a, $b) => $b['Timestamp'] <=> $a['Timestamp']);

        return isset($datapoints[0][$stat]) ? (float) $datapoints[0][$stat] : null;
    }
}
