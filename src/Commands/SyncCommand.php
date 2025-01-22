<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Commands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

class SyncCommand extends SteppedCommand
{
    protected function configure(): void
    {
        $this
            ->setName('sync')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync all resources for the given environment');
    }

    public function handle(): void
    {
        intro("Executing sync commands...");

        collect([
            Commands\SyncNetworkCommand::class,
            Commands\SyncStorageCommand::class,
//            Commands\SyncDomainCommand::class,
//            Commands\SyncMultitenancyLandlordCommand::class,
//            Commands\SyncMultitenancyTenantsCommand::class,
            Commands\SyncComputeCommand::class,
            Commands\SyncCiCommand::class,
        ])->each(fn ($command) => (new $command())->execute(Helpers::app('input'), Helpers::app('output')));

        info('Sync command executed successfully.');
    }
}
