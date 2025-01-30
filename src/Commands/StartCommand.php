<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Symfony\Component\Console\Input\InputArgument;

class StartCommand extends SteppedCommand implements RunsOnAws
{
    protected array $steps = [
        Steps\Start\All\SyncBashProfileStep::class, // all
        Steps\Start\Scheduler\ExecuteDeployStepsStep::class, // scheduler - note: migrations run here
        Steps\Start\All\ExecuteAllGroupsDeployStepsStep::class, // all
        Steps\Start\All\SetOwnershipAndPermissionsStep::class,
        Steps\Start\All\SyncLogrotateStep::class, // all
        Steps\Start\All\SyncHousekeepingCronStep::class, // all
        Steps\Start\Scheduler\SyncSchedulerCronStep::class, // scheduler
        Steps\Start\All\SyncPulseWorkerStep::class, // all
        Steps\Start\Queue\SyncQueueWorkerStep::class,  // queue
        Steps\Start\Queue\SyncQueueLandlordWorkerStep::class,  // queue
        Steps\Start\Queue\SyncQueueTenantWorkerStep::class, // queue
        Steps\Start\Web\SyncOctaneWorkerStep::class, // web
        Steps\Start\Scheduler\SyncMysqlBackupStep::class, // scheduler
        Steps\Start\All\SyncPhpConfigurationStep::class, // all
        Steps\Start\Web\SyncNginxConfigurationStep::class, // web
        Steps\Start\All\RestartServicesStep::class, // all
        Steps\Start\Web\WarmApplicationStep::class, // web
        Steps\Start\Web\WarmMultitenantedApplicationStep::class, // web
        Steps\Start\Web\ConfigureLoadBalancingStep::class, // web
    ];

    protected function configure(): void
    {
        $this
            ->setName('start')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'The app version to tag the build with')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Prepare the server for a new deployment');
    }
}
