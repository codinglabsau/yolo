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
        // sync composes the tier commands' scopes but not their handle(), so the
        // app tier's gates are re-applied here.
        if (! $this->ensureClaimedServicesOffered()) {
            return self::FAILURE;
        }

        if (! $this->ensureAppBucketAdoptable()) {
            return self::FAILURE;
        }

        return parent::handle();
    }

    #[\Override]
    public function warnings(): array
    {
        return [
            ...(new SyncAccountCommand())->warnings(),
            ...(new SyncEnvironmentCommand())->warnings(),
            ...(new SyncAppCommand())->warnings(),
        ];
    }

    #[\Override]
    public function guardedScopes(): array
    {
        return [
            ...(new SyncAccountCommand())->guardedScopes(),
            ...(new SyncEnvironmentCommand())->guardedScopes(),
            ...(new SyncAppCommand())->guardedScopes(),
        ];
    }

    /**
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
