<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Symfony\Component\Console\Input\InputArgument;

class StartCommand extends SteppedCommand implements RunsOnAws
{
    protected array $steps = [
        Steps\Start\All\SyncBashProfileStep::class,
        Steps\Start\Scheduler\ExecuteSchedulerDeployStepsStep::class,
        Steps\Start\Queue\ExecuteQueueDeployStepsStep::class,
        Steps\Start\Web\ExecuteWebDeployStepsStep::class,
        Steps\Start\All\ExecuteAllGroupsDeployStepsStep::class,
        Steps\Start\All\SetOwnershipAndPermissionsStep::class,
        Steps\Start\All\SyncLogrotateStep::class,
        Steps\Start\All\SyncHousekeepingCronStep::class,
        Steps\Start\Scheduler\SyncSchedulerCronStep::class,
        Steps\Start\Queue\SyncQueueWorkerStep::class,
        Steps\Start\Queue\SyncQueueLandlordWorkerStep::class,
        Steps\Start\Queue\SyncQueueTenantWorkerStep::class,
        Steps\Start\Web\SyncOctaneWorkerStep::class,
        Steps\Start\Scheduler\SyncMysqlBackupStep::class,
        Steps\Start\All\SyncPhpConfigurationStep::class,
        Steps\Start\Web\SyncNginxConfigurationStep::class,
        Steps\Start\All\RestartServicesStep::class,
        Steps\Start\Web\WarmApplicationStep::class,
        Steps\Start\Web\WarmMultitenantedApplicationStep::class,
        Steps\Start\Web\ConfigureLoadBalancingStep::class,
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
