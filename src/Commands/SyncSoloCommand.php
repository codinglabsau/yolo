<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncSoloCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Solo\SyncHostedZoneStep::class,
        Steps\Solo\SyncSslCertificateStep::class,
        Steps\Solo\SyncQueueStep::class,
        Steps\Solo\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:solo')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync AWS resources for a solo app');
    }
}
