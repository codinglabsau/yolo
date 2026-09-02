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
 * The grant layer: an IAM group whose inline policy allows `sts:AssumeRole` on one
 * tier role plus self-service credential hygiene scoped to `${aws:username}`.
 * Membership IS the access lever; YOLO never manages it.
 *
 * The self-service slice is the force-MFA shape: without MFA a member can only enrol
 * their first device (plus the reads the console and credential helper need for that
 * flow — see {@see Aws::callerMfaSerial()}); creating/rotating keys, changing the
 * password and deactivating the device demand `aws:MultiFactorAuthPresent`. A stolen
 * bare key can mint nothing, can't cut a replacement key, and can't strip the device
 * containing it.
 *
 * The document is deterministic (no live lookups) so it survives the plan pass with
 * nothing created. IAM groups are not taggable, so ownership is encoded in the name —
 * `yolo audit` can't see them; sync drift is the only stray-catcher.
 */
abstract class AssumeRoleGroup implements Deletable, Resource, SynchronisesConfiguration
{
    use CanonicalisesPolicyDocuments;
    use ResolvesTags;

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

    /** IAM groups have no tagging API; the name carries ownership. */
    public function synchroniseTags(bool $apply): array
    {
        return [];
    }

    /**
     * Compared canonically ({@see CanonicalisesPolicyDocuments}) so IAM's reordering
     * and single-element collapsing never read as drift.
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
     * IAM refuses to delete a group that still has members, attached or inline
     * policies, so strip all three first.
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
     * Widened by groups whose tier subsumes narrower ones. Must stay deterministic —
     * built from account/env only, never the current app.
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
        // CreateVirtualMFADevice/DeleteVirtualMFADevice evaluate against the device
        // ARN, and a virtual device has no owner until enabled, so scoping its name
        // buys nothing — the boundary is Deactivate, user-scoped and MFA-gated.
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
                // The MFA bootstrap path — deliberately NOT MFA-gated, or a new user
                // could never enrol. GetUser: the console's credentials page reads it.
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
                // Console MFA/password screens need these; neither is resource-scopeable.
                [
                    'Effect' => 'Allow',
                    'Action' => [
                        'iam:ListVirtualMFADevices',
                        'iam:GetAccountPasswordPolicy',
                    ],
                    'Resource' => '*',
                ],
                // MFA required, so a leaked bare key or pre-MFA session can't cut a
                // fresh key, change the password, or remove the device.
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
