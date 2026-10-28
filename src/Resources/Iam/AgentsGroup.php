<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;

/**
 * The one grant {@see AgentUser} holds. Unlike the human groups it carries no
 * self-service slice: anyone holding the shared key could otherwise cut a second
 * key outside the vault (and outside `agent:rotate`), or strip the MFA device.
 *
 * The assume is conditioned on a source identity being set, so every agent session
 * names the developer whose agent it is — an unstamped assume is refused.
 */
class AgentsGroup extends AssumeRoleGroup
{
    public function name(): string
    {
        return $this->keyedName(Iam::AGENTS_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    protected function role(): Resource
    {
        return new AgentRole();
    }

    #[\Override]
    public function document(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => ['sts:AssumeRole', 'sts:SetSourceIdentity'],
                    'Resource' => $this->assumableRoleArns(),
                    'Condition' => [
                        'Null' => ['sts:SourceIdentity' => 'false'],
                    ],
                ],
                // The credential helper discovers the MFA serial it pairs with the
                // vault's TOTP before any MFA session exists.
                [
                    'Effect' => 'Allow',
                    'Action' => 'iam:ListMFADevices',
                    'Resource' => sprintf('arn:aws:iam::%s:user/${aws:username}', Aws::accountId()),
                ],
            ],
        ];
    }
}
