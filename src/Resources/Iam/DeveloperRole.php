<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Iam;

/**
 * The Developer tier's own role: everything the observer reads plus the database
 * dumps ({@see DataReadPolicy} + {@see DumpsReadPolicy}) — no writes at all. A
 * developer ships through the per-app deployer roles, which {@see DevelopersGroup}
 * also grants, so deploy, `run` and `.env` stay capped to that deploy policy.
 *
 * Same account-root + MFA trust as the observer role; {@see DevelopersGroup}
 * membership is the gate. No YOLO command mints this tier — it's assumed
 * directly from a developer's profile.
 */
class DeveloperRole extends ObserverRole
{
    #[\Override]
    public function name(): string
    {
        return $this->keyedName(Iam::DEVELOPER_ROLE);
    }

    #[\Override]
    public function description(): string
    {
        return 'YOLO managed developer role: read-only inspection plus database dump reads';
    }
}
