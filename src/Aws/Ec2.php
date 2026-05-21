<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Ec2
{
    /** @var array<string, array<string, mixed>> */
    protected static array $securityGroups = [];

    public static function securityGroup(string $name, bool $refresh = false): array
    {
        if (! $refresh && isset(static::$securityGroups[$name])) {
            return static::$securityGroups[$name];
        }

        $securityGroups = Aws::ec2()->describeSecurityGroups()['SecurityGroups'];

        foreach ($securityGroups as $securityGroup) {
            if ($securityGroup['GroupName'] === $name) {
                return static::$securityGroups[$name] = $securityGroup;
            }
        }

        throw new ResourceDoesNotExistException("Could not find security group $name");
    }

    /**
     * Lists ingress/egress rules for a security group, optionally filtered by a
     * `yolo:rule-type` tag value so callers can find their specific rule among
     * any custom ones.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function securityGroupRules(string $groupId, ?string $ruleType = null): array
    {
        $filters = [['Name' => 'group-id', 'Values' => [$groupId]]];

        if ($ruleType !== null) {
            $filters[] = ['Name' => 'tag:yolo:rule-type', 'Values' => [$ruleType]];
        }

        return Aws::ec2()->describeSecurityGroupRules([
            'Filters' => $filters,
        ])['SecurityGroupRules'];
    }
}
