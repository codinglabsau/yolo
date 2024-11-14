<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncMultitenancyTenantsCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Tenant\SyncHostedZoneStep::class,
        Steps\Tenant\SyncSslCertificateStep::class,
        Steps\Tenant\SyncQueueStep::class,
        Steps\Tenant\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:multitenancy-tenants')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync configured tenant AWS resources');
    }
}
