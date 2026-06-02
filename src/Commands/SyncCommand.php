<?php

namespace Codinglabs\Yolo\Commands;

use function Laravel\Prompts\warning;

class SyncCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync')
            ->setDescription('Sync all resources for the given environment (account → environment → app)');
    }

    public function handle(): int
    {
        // The orchestrating `sync` composes SyncAppCommand's scopes but not its
        // handle(), so surface the app-level scheduler advisory here too — it's the
        // command most people run.
        if ($advisory = SyncAppCommand::schedulerAdvisory()) {
            warning($advisory);
        }

        return parent::handle();
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
