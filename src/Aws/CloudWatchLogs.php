<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class CloudWatchLogs
{
    public static function logGroup(string $name): array
    {
        try {
            $groups = Aws::cloudWatchLogs()->describeLogGroups([
                'logGroupNamePrefix' => $name,
            ])['logGroups'];
        } catch (AwsException) {
            throw new ResourceDoesNotExistException("Could not find CloudWatch log group $name");
        }

        foreach ($groups as $group) {
            if ($group['logGroupName'] === $name) {
                return $group;
            }
        }

        throw new ResourceDoesNotExistException("Could not find CloudWatch log group $name");
    }
}
