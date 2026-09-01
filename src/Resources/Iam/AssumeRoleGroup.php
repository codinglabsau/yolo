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
 * `sts:AssumeRole` on exactly one scoped tier role, plus the self-service slice
 * every member needs to run their own credential hygiene — scoped to
 * `${aws:username}`, so a member only ever touches their own user. Membership IS
 * the access lever — add a user to the group to grant the tier, remove to
 * revoke. YOLO provisions and reconciles the group + its policy; it never
 * manages membership (that's the human lever, held by an admin via
 * `yolo permissions` or the console).
 *
 * The self-service slice is the standard force-MFA shape, split on one line:
 * what a member may do WITHOUT MFA is exactly the bootstrap path (enrol their
 * own device, plus the reads the console needs to render that flow — a
 * brand-new user signs in with just a password and must be able to reach MFA
 * enrolment, and the credential helper auto-discovers the serial via
 * `iam:ListMFADevices` at mint time, see {@see Aws::callerMfaSerial()});
 * everything else — creating and rotating their own access keys, changing the
 * password, deactivating or deleting the device — demands
 * `aws:MultiFactorAuthPresent`. A stolen bare key or pre-MFA console session
 * can therefore mint nothing (every tier's trust denies AssumeRole without
 * MFA), can't cut itself a replacement key, and can't strip the MFA device
 * that's containing it. The developer's first access key is self-issued AFTER
 * enrolment: sign in with the password, enrol, re-sign-in with a TOTP, then
 * create the key under the MFA gate — the key never exists before the device.
 *
 * The inline document is pure and deterministic (the role ARN and the member's
 * user/mfa ARNs are built from account/env/app + the `${aws:username}` policy
 * variable, never a live lookup), so it survives the sync two-pass contract with
 * nothing created yet. IAM groups are not taggable, so ownership
 * is encoded in the name (`yolo-{env}[-{app}]-{tier}s`) rather than a `yolo:*`
 * tag — which is why `yolo audit` can't see them (the same blind spot it has for
 * scaling policies); sync-drift is the only stray-catcher.
 */
abstract class AssumeRoleGroup implements Deletable, Resource, SynchronisesConfiguration
{
    use CanonicalisesPolicyDocuments;
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
     * either way the plan records it and apply re-puts it. Compared canonically
     * (see {@see CanonicalisesPolicyDocuments}) so IAM's list reordering and
     * single-element collapsing never read as phantom drift. Reads only this
     * group's own policy, behind the exists() gate in syncResource — two-pass safe.
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $desired = $this->document();
        $live = IamClient::groupPolicy($this->name(), $this->policyName());

        if ($live !== null && $this->policyDocumentsMatch($live, $desired)) {
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
     * The role ARN(s) this group's members may assume — the tier role by
     * default. A group whose tier subsumes narrower tiers widens this (the env
     * observers group adds every per-app observer role, so an env-wide reader
     * can run app-scoped commands that mint the narrower role). Must stay
     * deterministic — built from account/env only, never the current app.
     *
     * @return string|array<int, string>
     */
    protected function assumableRoleArns(): string|array
    {
        return sprintf(
            'arn:aws:iam::%s:role/%s',
            Aws::accountId(),
            $this->role()->name(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        // Every self-service IAM action here authorises on the member's own
        // user ARN except CreateVirtualMFADevice/DeleteVirtualMFADevice, which
        // evaluate against the device ARN — and a virtual device has no owner
        // until it's enabled, so scoping its name (mfa/${aws:username}) only
        // buys a console paper cut, not security: the boundary that matters is
        // Deactivate, which is user-scoped and MFA-gated. Devices may be named
        // freely; delete only ever works on deactivated device objects.
        $self = [
            sprintf('arn:aws:iam::%s:user/${aws:username}', Aws::accountId()),
            sprintf('arn:aws:iam::%s:mfa/*', Aws::accountId()),
        ];

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => 'sts:AssumeRole',
                    'Resource' => $this->assumableRoleArns(),
                ],
                // The MFA bootstrap path — deliberately NOT MFA-gated, or a new
                // user could never enrol their first device (and the credential
                // helper couldn't discover the serial to mint with). GetUser is
                // here because the console's security-credentials page reads it.
                [
                    'Effect' => 'Allow',
                    'Action' => [
                        'iam:GetUser',
                        'iam:GetMFADevice',
                        'iam:ListMFADevices',
                        'iam:CreateVirtualMFADevice',
                        'iam:EnableMFADevice',
                        'iam:ResyncMFADevice',
                    ],
                    'Resource' => $self,
                ],
                // Account-level reads the console's MFA and password screens
                // need to render — neither supports resource-level scoping.
                [
                    'Effect' => 'Allow',
                    'Action' => [
                        'iam:ListVirtualMFADevices',
                        'iam:GetAccountPasswordPolicy',
                    ],
                    'Resource' => '*',
                ],
                // Credential self-management — MFA required, so a leaked bare
                // key (or a pre-MFA console session) can't cut a fresh key,
                // change the password, or remove the device.
                [
                    'Effect' => 'Allow',
                    'Action' => [
                        'iam:CreateAccessKey',
                        'iam:ListAccessKeys',
                        'iam:UpdateAccessKey',
                        'iam:DeleteAccessKey',
                        'iam:ChangePassword',
                        'iam:DeactivateMFADevice',
                        'iam:DeleteVirtualMFADevice',
                    ],
                    'Resource' => $self,
                    'Condition' => [
                        'Bool' => ['aws:MultiFactorAuthPresent' => 'true'],
                    ],
                ],
            ],
        ];
    }
}
