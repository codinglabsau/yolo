<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class Iam
{
    /**
     * [] when the role doesn't exist yet — the plan pass reaches the attach step
     * before the role's own create has run.
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

    /**
     * A path-scoped ListRoles pins the check to the one role without paginating
     * the account — and it's a collection op the observer tier already grants,
     * so no aws-service-role read grant is needed.
     */
    public static function serviceLinkedRoleExists(string $serviceName): bool
    {
        return Aws::iam()->listRoles([
            'PathPrefix' => sprintf('/aws-service-role/%s/', $serviceName),
        ])['Roles'] !== [];
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
     * A managed policy holds at most 5 versions; the document reconciler prunes
     * the oldest non-default one before pushing a new version.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function policyVersions(string $policyArn): array
    {
        return Aws::iam()->listPolicyVersions([
            'PolicyArn' => $policyArn,
        ])['Versions'] ?? [];
    }

    /**
     * OIDC providers have no name field — only the ARN identifies one.
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

    public static function group(string $name): array
    {
        try {
            // GetGroup is scopeable to the group ARN — keeps the admin tier's
            // group reads fenced to yolo-*.
            return Aws::iam()->getGroup(['GroupName' => $name])['Group'];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NoSuchEntity') {
                throw new ResourceDoesNotExistException("Could not find IAM group $name", $e->getCode(), $e);
            }

            throw $e;
        }
    }

    /**
     * AWS returns PolicyDocument url-encoded.
     *
     * @return array<string, mixed>|null
     */
    public static function groupPolicy(string $groupName, string $policyName): ?array
    {
        try {
            $result = Aws::iam()->getGroupPolicy([
                'GroupName' => $groupName,
                'PolicyName' => $policyName,
            ]);

            return json_decode(urldecode((string) $result['PolicyDocument']), true);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NoSuchEntity') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function users(): array
    {
        return Aws::iam()->listUsers()['Users'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public static function groupsForUser(string $userName): array
    {
        $groups = Aws::iam()->listGroupsForUser(['UserName' => $userName])['Groups'] ?? [];

        return array_map(static fn (array $group): string => $group['GroupName'], $groups);
    }
}
