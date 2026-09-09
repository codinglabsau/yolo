<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Account;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\UsersGroup;
use Codinglabs\Yolo\Commands\PermissionsCommand;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Creates/syncs the group + its self-service policy — the resource only.
 * Membership stays a human act, granted per user by {@see PermissionsCommand}:
 * sync provisions what the account may have, never who is in it.
 */
class SyncUsersGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new UsersGroup(), $options);
    }
}
