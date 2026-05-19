<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesCloudWatch
{
    public static function alarm(string $alarmName): array
    {
        $alarms = Aws::cloudWatch()->describeAlarms();

        foreach ($alarms['MetricAlarms'] as $alarm) {
            if ($alarm['AlarmName'] === $alarmName) {
                return $alarm;
            }
        }

        throw new ResourceDoesNotExistException("Could not find alarm with name $alarmName");
    }
}
