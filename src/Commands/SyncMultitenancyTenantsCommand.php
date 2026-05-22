<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

class SyncMultitenancyTenantsCommand extends SyncSteppedCommand
{
    protected array $steps = [
        //        Steps\Tenant\SyncHostedZoneStep::class,
        //        Steps\Deploy\SyncMultitenancyRecordSetStep::class,
        //        Steps\Tenant\SyncSslCertificateStep::class,
        //        Steps\Tenant\AttachSslCertificateToLoadBalancerListenerStep::class,
        Steps\Tenant\SyncQueueStep::class,
        Steps\Tenant\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:multitenancy-tenants')
            ->setDescription('Sync configured tenant AWS resources');
    }
}
