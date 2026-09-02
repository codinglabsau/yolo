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

    /**
     * A missing group reads as no events — the Logs tab shows an empty state
     * until the first task logs.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(string $logGroup, string $streamPrefix, int $limit = 60): array
    {
        try {
            return Aws::cloudWatchLogs()->filterLogEvents([
                'logGroupName' => $logGroup,
                'logStreamNamePrefix' => $streamPrefix,
                'limit' => $limit,
            ])['events'] ?? [];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return [];
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resourcePolicy(string $name): ?array
    {
        foreach (Aws::cloudWatchLogs()->describeResourcePolicies()['resourcePolicies'] ?? [] as $policy) {
            if (($policy['policyName'] ?? null) === $name) {
                return json_decode((string) $policy['policyDocument'], true);
            }
        }

        return null;
    }
}
