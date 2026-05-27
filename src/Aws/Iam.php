<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Iam
{
    /**
     * The managed policies attached to a role, as [{PolicyName, PolicyArn}], or
     * an empty list when the role does not yet exist (a dry-run can reach the
     * attach step before the role's own create step has run).
     *
     * @return array<int, array<string, string>>
     */
    public static function attachedRolePolicies(string $roleName): array
    {
        try {
            return Aws::iam()->listAttachedRolePolicies(['RoleName' => $roleName])['AttachedPolicies'] ?? [];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NoSuchEntity') {
                return [];
            }

            throw $e;
        }
    }

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

    /**
     * OIDC providers are account-level singletons keyed by their full ARN
     * (no name field) — match the list entry by ARN.
     */
    public static function openIdConnectProvider(string $arn): array
    {
        $providers = Aws::iam()->listOpenIDConnectProviders();

        foreach ($providers['OpenIDConnectProviderList'] as $provider) {
            if ($provider['Arn'] === $arn) {
                return $provider;
            }
        }

        throw new ResourceDoesNotExistException("Could not find OIDC provider $arn");
    }
}
