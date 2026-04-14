<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesCloudWatchLogs
{
    protected static array $logGroups = [];

    public static function logGroup(string $name): array
    {
        if (isset(static::$logGroups[$name])) {
            return static::$logGroups[$name];
        }

        $result = Aws::cloudWatchLogs()->describeLogGroups([
            'logGroupNamePrefix' => $name,
        ]);

        foreach ($result['logGroups'] as $logGroup) {
            if ($logGroup['logGroupName'] === $name) {
                static::$logGroups[$name] = $logGroup;

                return static::$logGroups[$name];
            }
        }

        throw new ResourceDoesNotExistException("Could not find CloudWatch log group with name $name");
    }
}
