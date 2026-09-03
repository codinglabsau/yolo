<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

class SyncAccountCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:account')
            ->setDescription('Sync the account-global resources (shared across every environment)');
    }

    #[\Override]
    public function handle(): int
    {
        if (! $this->ensureCliNotOlderThanEnvironment()) {
            return self::FAILURE;
        }

        return parent::handle();
    }

    public function scopes(): array
    {
        return [
            'account' => [
                Steps\Sync\Account\SyncServiceLinkedRolesStep::class,
                Steps\Sync\Account\SyncGithubOidcProviderStep::class,
            ],
        ];
    }
}
