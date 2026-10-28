<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Iam;

/**
 * The coding-agent tier: carries {@see AgentPolicy} only, so an agent reads the
 * environment's infrastructure but never its data, its dumps or a database tunnel.
 *
 * Trust adds `sts:SetSourceIdentity` to the MFA-gated assume: {@see AgentsGroup}
 * refuses an assume that doesn't stamp the developer behind the agent, and a
 * source identity can't be changed for the life of the session.
 */
class AgentRole extends ObserverRole
{
    #[\Override]
    public function name(): string
    {
        return $this->keyedName(Iam::AGENT_ROLE);
    }

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    #[\Override]
    public function description(): string
    {
        return 'YOLO managed read-only role for coding agents inspecting this environment';
    }

    #[\Override]
    public function assumeRolePolicyDocument(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => sprintf('arn:aws:iam::%s:root', Aws::accountId())],
                    'Action' => ['sts:AssumeRole', 'sts:SetSourceIdentity'],
                    'Condition' => [
                        'Bool' => ['aws:MultiFactorAuthPresent' => 'true'],
                    ],
                ],
            ],
        ];
    }
}
