<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Iam;

/**
 * The Developer tier: everything the observer reads plus writes to the apps'
 * *data* ({@see DataReadPolicy} + {@see DataWritePolicy}) — and nothing on the
 * infrastructure axis. No sync, no scale, and no `yolo run` / `db:backup`
 * either: those run arbitrary code in the app's task role, which reaches every
 * secret the app holds, not just its data.
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
        return 'YOLO managed developer role: read-only inspection plus app data writes, no infrastructure writes';
    }
}
