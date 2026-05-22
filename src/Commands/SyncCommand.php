<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Manifest;

class SyncCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync')
            ->setDescription('Sync all resources for the given environment');
    }

    public function handle(): int
    {
        return $this->runDomains($this->argument('environment'), $this->domains());
    }

    /**
     * The ordered, domain-labelled steps this environment will sync.
     *
     * @return array<string, array<int, class-string>>
     */
    protected function domains(): array
    {
        return [
            'Network' => (new SyncNetworkCommand())->steps(),
            'Storage' => (new SyncStorageCommand())->steps(),
            'IAM' => (new SyncIamCommand())->steps(),
            ...Manifest::isMultitenanted()
                ? [
                    'Landlord' => (new SyncMultitenancyLandlordCommand())->steps(),
                    'Tenants' => (new SyncMultitenancyTenantsCommand())->steps(),
                ]
                : [
                    'Solo' => (new SyncSoloCommand())->steps(),
                ],
            ...Manifest::has('tasks.web')
                ? ['Compute' => (new SyncComputeCommand())->steps()]
                : [],
            'Logging' => (new SyncLoggingCommand())->steps(),
        ];
    }
}
