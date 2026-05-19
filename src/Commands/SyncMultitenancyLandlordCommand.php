<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncMultitenancyLandlordCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Landlord\SyncQueueStep::class,
        Steps\Landlord\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:multitenancy-landlord')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync configured landlord AWS resources');
    }
}
