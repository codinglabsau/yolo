<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;

/**
 * The self-service credential-hygiene slice: MFA enrolment plus MFA-gated
 * key/password/device management, scoped to the member's own user/mfa ARNs.
 * Used exclusively by {@see UsersGroup} — every IAM user gets exactly this,
 * independent of whatever tier (if any) they're also granted.
 */
trait GrantsSelfServiceMfa
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function selfServiceStatements(): array
    {
        // CreateVirtualMFADevice/DeleteVirtualMFADevice evaluate against the device
        // ARN, and a virtual device has no owner until enabled, so scoping its name
        // buys nothing — the boundary is Deactivate, user-scoped and MFA-gated.
        $self = [
            sprintf('arn:aws:iam::%s:user/${aws:username}', Aws::accountId()),
            sprintf('arn:aws:iam::%s:mfa/*', Aws::accountId()),
        ];

        return [
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
        ];
    }
}
