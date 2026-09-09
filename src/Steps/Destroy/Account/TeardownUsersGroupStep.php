<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Account;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Codinglabs\Yolo\Resources\Iam\UsersGroup;
use Codinglabs\Yolo\Concerns\GuardsLastEnvironment;
use Codinglabs\Yolo\Resources\Iam\GithubOidcProvider;

/**
 * Account-shared like {@see GithubOidcProvider} — every environment's users
 * are enrolled in this one group, so it goes only with the last environment,
 * and never on a guess.
 */
class TeardownUsersGroupStep implements Step
{
    use GuardsLastEnvironment;
    use RecordsChanges;
    use RecordsWarnings;

    public function __invoke(array $options): StepResult
    {
        $group = new UsersGroup();

        if (! $group->exists()) {
            return StepResult::SKIPPED;
        }

        try {
            $others = $this->otherEnvironments();
        } catch (\Throwable $exception) {
            $this->recordWarning(sprintf(
                'Kept the account-shared %s group — could not verify whether other environments exist (%s). It is reclaimed only once that is confirmed.',
                $group->name(),
                $exception->getMessage(),
            ));

            return StepResult::SKIPPED;
        }

        if ($others !== []) {
            $this->recordWarning(sprintf(
                'Kept the account-shared %s group — other environments still exist (%s). It is reclaimed only when the last environment is torn down.',
                $group->name(),
                implode(', ', $others),
            ));

            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make($group->name(), 'provisioned', null));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $group->delete();

        return StepResult::DELETED;
    }
}
