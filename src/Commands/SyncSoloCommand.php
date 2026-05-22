<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

class SyncSoloCommand extends SyncSteppedCommand
{
    protected array $steps = [
        Steps\Solo\SyncHostedZoneStep::class,
        Steps\Solo\SyncSslCertificateStep::class,
        Steps\Solo\SyncQueueStep::class,
        Steps\Solo\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:solo')
            ->setDescription('Sync AWS resources for a solo app');
    }
}
