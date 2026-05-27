<?php

namespace Codinglabs\Yolo\Commands;

class SyncCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync')
            ->setDescription('Sync all resources for the given environment (account → environment → app)');
    }

    /**
     * The full sync orchestrates the three scopes in dependency order — account,
     * then the env-shared environment tier, then this app. Each command keys its
     * steps under its own scope (account / environment / app), so merging the
     * three composes cleanly into the ordered scope map the renderer groups by.
     *
     * @return array<string, array<int, class-string>>
     */
    public function scopes(): array
    {
        return [
            ...(new SyncAccountCommand())->scopes(),
            ...(new SyncEnvironmentCommand())->scopes(),
            ...(new SyncAppCommand())->scopes(),
        ];
    }
}
