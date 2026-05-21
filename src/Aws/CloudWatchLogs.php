<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class CloudWatchLogs
{
    /** @var array<string, array<string, mixed>> */
    protected static array $logGroups = [];

    public static function logGroup(string $name, bool $refresh = false): array
    {
        if (! $refresh && isset(static::$logGroups[$name])) {
            return static::$logGroups[$name];
        }

        try {
            $groups = Aws::cloudWatchLogs()->describeLogGroups([
                'logGroupNamePrefix' => $name,
            ])['logGroups'];
        } catch (AwsException) {
            throw new ResourceDoesNotExistException("Could not find CloudWatch log group $name");
        }

        foreach ($groups as $group) {
            if ($group['logGroupName'] === $name) {
                return static::$logGroups[$name] = $group;
            }
        }

        throw new ResourceDoesNotExistException("Could not find CloudWatch log group $name");
    }
}
