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
     * The account-level resource policy with the given name decoded to an array,
     * or null when no such policy exists. Used to diff the EventBridge log-delivery
     * grant before re-putting it.
     *
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
