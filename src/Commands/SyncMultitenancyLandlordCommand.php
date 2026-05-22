<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

class SyncMultitenancyLandlordCommand extends SyncSteppedCommand
{
    protected array $steps = [
        Steps\Landlord\SyncQueueStep::class,
        Steps\Landlord\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:multitenancy-landlord')
            ->setDescription('Sync configured landlord AWS resources');
    }
}
