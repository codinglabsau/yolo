<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Iam
{
    public static function role(string $name): array
    {
        $roles = Aws::iam()->listRoles();

        foreach ($roles['Roles'] as $role) {
            if ($role['RoleName'] === $name) {
                return $role;
            }
        }

        throw new ResourceDoesNotExistException("Could not find IAM role $name");
    }

    public static function policy(string $name): array
    {
        $policies = Aws::iam()->listPolicies([
            'Scope' => 'Local',
        ]);

        foreach ($policies['Policies'] as $policy) {
            if ($policy['PolicyName'] === $name) {
                return $policy;
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
