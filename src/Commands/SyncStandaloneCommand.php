<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncStandaloneCommand extends SteppedCommand
{
    protected array $steps = [
        //        Steps\Standalone\SyncHostedZoneStep::class,
        //        Steps\Standalone\SyncSslCertificateStep::class,
        Steps\Standalone\SyncQueueStep::class,
        Steps\Standalone\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:standalone')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync AWS resources for standalone app');
    }
}
