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
}
