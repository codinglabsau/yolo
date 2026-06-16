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
     * Every version of a managed policy, as [{VersionId, IsDefaultVersion,
     * CreateDate}]. A managed policy holds at most 5; the document reconciler
     * prunes the oldest non-default version before pushing a new one.
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

    public static function group(string $name): array
    {
        $groups = Aws::iam()->listGroups();

        foreach ($groups['Groups'] as $group) {
            if ($group['GroupName'] === $name) {
                return $group;
            }
        }

        throw new ResourceDoesNotExistException("Could not find IAM group $name");
    }

    /**
     * The decoded document of an inline group policy, or null when the group has
     * no such inline policy yet (a partially-created group, or the first sync).
     * AWS returns PolicyDocument url-encoded — decode it so callers diff a plain
     * array against their desired document.
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
     * Every IAM user in the account, as [{UserName, Arn, …}] — the picker source
     * for `yolo permissions`. A collection op with no resource-level form.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function users(): array
    {
        return Aws::iam()->listUsers()['Users'] ?? [];
    }

    /**
     * The names of every group a user belongs to — the current grant set the
     * `yolo permissions` checkboxes are seeded from.
     *
     * @return array<int, string>
     */
    public static function groupsForUser(string $userName): array
    {
        $groups = Aws::iam()->listGroupsForUser(['UserName' => $userName])['Groups'] ?? [];

        return array_map(static fn (array $group): string => $group['GroupName'], $groups);
    }
}
