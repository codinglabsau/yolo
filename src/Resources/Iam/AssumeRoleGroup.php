<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The grant layer: a YOLO-managed IAM group whose single inline policy allows
 * `sts:AssumeRole` on exactly one scoped tier role. Membership IS the access
 * lever — add a user to the group to grant the tier, remove to revoke. YOLO
 * provisions and reconciles the group + its policy; it never manages membership
 * (that's the human lever, held by an admin via `yolo permissions` or the
 * console).
 *
 * The inline document is pure and deterministic (the role ARN is built from
 * account/env/app, never a live lookup), so it survives the sync two-pass
 * contract with nothing created yet. IAM groups are not taggable, so ownership
 * is encoded in the name (`yolo-{env}[-{app}]-{tier}s`) rather than a `yolo:*`
 * tag — which is why `yolo audit` can't see them (the same blind spot it has for
 * scaling policies); sync-drift is the only stray-catcher.
 */
abstract class AssumeRoleGroup implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    /** The scoped tier role this group's members may assume. */
    abstract protected function role(): Resource;

    public function exists(): bool
    {
        try {
            IamClient::group($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return IamClient::group($this->name())['Arn'];
    }

    public function create(): void
    {
        Aws::iam()->createGroup(['GroupName' => $this->name()]);

        Aws::iam()->putGroupPolicy([
            'GroupName' => $this->name(),
            'PolicyName' => $this->policyName(),
            'PolicyDocument' => json_encode($this->document()),
        ]);
    }

    /**
     * IAM groups have no tagging API, so there is nothing to reconcile — the
     * name carries ownership. Returning no missing tags keeps the create-or-sync
     * flow honest (an existing group with the right inline policy is a clean
     * SYNCED, never a phantom tag change).
     */
    public function synchroniseTags(bool $apply): array
    {
        return [];
    }

    /**
     * Reconcile the inline assume-role policy. The document is deterministic, so
     * the only drift is a YOLO upgrade that changed its shape (or a hand-edit);
     * either way the plan records it and apply re-puts it. Reads only this
     * group's own policy, behind the exists() gate in syncResource — two-pass safe.
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $desired = $this->document();
        $live = IamClient::groupPolicy($this->name(), $this->policyName());

        if ($live === $desired) {
            return [];
        }

        if ($apply) {
            Aws::iam()->putGroupPolicy([
                'GroupName' => $this->name(),
                'PolicyName' => $this->policyName(),
                'PolicyDocument' => json_encode($desired),
            ]);
        }

        return [Change::make(
            sprintf('%s assume-role policy', $this->policyName()),
            $live === null ? 'missing' : 'drifted',
            'reconciled',
        )];
    }

    /**
     * Teardown when the tier is dropped (the app loses its deployer, or the
     * environment is torn down): IAM refuses to delete a group that still has
     * members, attached managed policies, or inline policies, so remove every
     * user from the group, detach every managed policy, and delete the inline
     * assume-role policy (create()'s put) before deleteGroup. A concurrent delete
     * that already removed the group is tolerated.
     */
    public function delete(): void
    {
        try {
            $members = Aws::iam()->getGroup([
                'GroupName' => $this->name(),
            ])['Users'] ?? [];

            foreach ($members as $user) {
                Aws::iam()->removeUserFromGroup([
                    'GroupName' => $this->name(),
                    'UserName' => $user['UserName'],
                ]);
            }

            $attached = Aws::iam()->listAttachedGroupPolicies([
                'GroupName' => $this->name(),
            ])['AttachedPolicies'] ?? [];

            foreach ($attached as $policy) {
                Aws::iam()->detachGroupPolicy([
                    'GroupName' => $this->name(),
                    'PolicyArn' => $policy['PolicyArn'],
                ]);
            }

            $inline = Aws::iam()->listGroupPolicies([
                'GroupName' => $this->name(),
            ])['PolicyNames'] ?? [];

            foreach ($inline as $policyName) {
                Aws::iam()->deleteGroupPolicy([
                    'GroupName' => $this->name(),
                    'PolicyName' => $policyName,
                ]);
            }

            Aws::iam()->deleteGroup([
                'GroupName' => $this->name(),
            ]);
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
        }
    }

    protected function policyName(): string
    {
        return sprintf('%s-assume', $this->name());
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => 'sts:AssumeRole',
                    'Resource' => sprintf(
                        'arn:aws:iam::%s:role/%s',
                        Aws::accountId(),
                        $this->role()->name(),
                    ),
                ],
            ],
        ];
    }
}
