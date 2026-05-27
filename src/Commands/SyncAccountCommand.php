<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

/**
 * Writer of account-global resources — one set per AWS account, shared by every
 * environment and app on it. Blast radius: the whole account.
 */
class SyncAccountCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:account')
            ->setDescription('Sync the account-global resources (shared across every environment)');
    }

    public function scopes(): array
    {
        return [
            'account' => [
                Steps\Sync\Account\SyncGithubOidcProviderStep::class,
            ],
        ];
    }
}
