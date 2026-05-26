<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
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
}
