<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;

class StartCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Start\SyncBashProfileStep::class, // all
        Steps\Start\ExecuteDeployStepsStep::class, // scheduler - note: migrations run here
        Steps\Start\ExecuteAllGroupsDeployStepsStep::class, // all
        Steps\Start\SetOwnershipAndPermissionsStep::class,
        Steps\Start\SyncLogrotateStep::class, // all
        Steps\Start\SyncHousekeepingCronStep::class, // all
        Steps\Start\SyncSchedulerCronStep::class, // scheduler
        Steps\Start\SyncPulseWorkerStep::class, // all
        Steps\Start\SyncLandlordQueueWorkerStep::class,  // queue
        Steps\Start\SyncTenantQueueWorkerStep::class, // queue
        Steps\Start\SyncOctaneWorkerStep::class, // web
        Steps\Start\SyncMysqlBackupStep::class, // scheduler
        Steps\Start\SyncPhpConfigurationStep::class, // all
        Steps\Start\SyncNginxConfigurationStep::class, // web
        Steps\Start\RestartServicesStep::class, // all
        Steps\Start\WarmApplicationStep::class, // web
        Steps\Start\ConfigureLoadBalancingStep::class, // web
    ];

    protected function configure(): void
    {
        $this
            ->setName('start')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'The app version to tag the build with')
            ->setDescription('Prepare the server for a new deployment');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        if (! Aws::runningInAws()) {
            error("You can only run the start command from within an AWS instance.");
            return;
        }

        info("Executing start steps...");

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
