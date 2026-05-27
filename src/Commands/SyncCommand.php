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
     * The full sync orchestrates the three tiers in dependency order — account,
     * then the env-shared environment tier, then this app. Each tier's labels are distinct,
     * so composing them preserves every group (a colliding label would drop one).
     *
     * @return array<string, array<int, class-string>>
     */
    public function domains(): array
    {
        return [
            ...(new SyncAccountCommand())->domains(),
            ...(new SyncEnvironmentCommand())->domains(),
            ...(new SyncAppCommand())->domains(),
        ];
    }
}
