<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncDomainCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Domain\SyncHostedZoneStep::class,
        Steps\Domain\SyncSslCertificateStep::class,
        Steps\Domain\SyncQueueStep::class,
        Steps\Domain\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:domain')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync configured domain AWS resources');
    }
}
