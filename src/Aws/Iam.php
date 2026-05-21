<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Iam
{
    /** @var array<string, array<string, mixed>> */
    protected static array $roles = [];

    /** @var array<string, array<string, mixed>> */
    protected static array $policies = [];

    public static function role(string $name, bool $refresh = false): array
    {
        if (! $refresh && isset(static::$roles[$name])) {
            return static::$roles[$name];
        }

        $roles = Aws::iam()->listRoles();

        foreach ($roles['Roles'] as $role) {
            if ($role['RoleName'] === $name) {
                return static::$roles[$name] = $role;
            }
        }

        throw new ResourceDoesNotExistException("Could not find IAM role $name");
    }

    public static function policy(string $name, bool $refresh = false): array
    {
        if (! $refresh && isset(static::$policies[$name])) {
            return static::$policies[$name];
        }

        $policies = Aws::iam()->listPolicies([
            'Scope' => 'Local',
        ]);

        foreach ($policies['Policies'] as $policy) {
            if ($policy['PolicyName'] === $name) {
                return static::$policies[$name] = $policy;
            }
        }

        throw new ResourceDoesNotExistException("Could not find IAM policy $name");
    }

    public static function policyVersion(string $policyArn, string $versionId): array
    {
        return Aws::iam()->getPolicyVersion([
            'PolicyArn' => $policyArn,
            'VersionId' => $versionId,
        ])['PolicyVersion'];
    }
}
