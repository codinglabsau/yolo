<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Credentials;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;

/**
 * The naming contract `agent:rotate` (writer) and `configure --agent` (reader)
 * share, so neither has to be told where the other put the agent's key.
 */
class AgentCredentials
{
    public const string DEFAULT_VAULT = 'YOLO Agents';

    /** Account-qualified: two accounts can each hold an environment of the same name. */
    public static function itemTitle(): string
    {
        return sprintf('YOLO %s %s agent', Aws::accountId(), Helpers::environment());
    }

    /**
     * The IAM username from a user ARN — verified against AWS, unique across the
     * team, and the name the developer's own CloudTrail events already carry.
     */
    public static function sourceIdentityFrom(string $arn): ?string
    {
        return preg_match('#:user/(?:.*/)?([\w+=,.@-]{2,64})$#', $arn, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * Who and where — one developer may run agents on several machines. STS caps
     * session names at 64 characters of `[\w+=,.@-]`.
     */
    public static function sessionName(string $sourceIdentity, string $host): string
    {
        $host = (string) preg_replace('/[^\w+=,.@-]/', '-', explode('.', $host)[0]);

        return substr(sprintf('agent-%s@%s', $sourceIdentity, $host), 0, 64);
    }
}
