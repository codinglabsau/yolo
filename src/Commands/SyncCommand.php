<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

class SyncCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync')
            ->setDescription('Sync all resources for the given environment (account → environment → app)');
    }

    #[\Override]
    public function handle(): int
    {
        // The orchestrating sync composes the tier commands' scopes but not
        // their handle(), so the app tier's claim-without-offer gate is
        // re-applied here.
        if (! $this->ensureClaimedServicesOffered()) {
            return self::FAILURE;
        }

        return parent::handle();
    }

    /**
     * The orchestrating `sync` composes the tier commands' scopes but not their
     * handle(), so compose their plan warnings the same way — deduplicated,
     * since an advisory both tiers raise (the version-skew warning) should
     * read once, not once per tier.
     */
    #[\Override]
    public function warnings(): array
    {
        return array_values(array_unique([
            ...(new SyncAccountCommand())->warnings(),
            ...(new SyncEnvironmentCommand())->warnings(),
            ...(new SyncAppCommand())->warnings(),
        ]));
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
