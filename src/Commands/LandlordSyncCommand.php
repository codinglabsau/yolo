<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;

class LandlordSyncCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Landlord\SyncQueueStep::class,
        Steps\Landlord\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('landlord:sync')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->setDescription('Sync configured landlord AWS resources');
    }

    public function handle(): void
    {
        if (Aws::runningInAws()) {
            error("landlord:sync command cannot be run in AWS.");
            return;
        }

        $environment = $this->argument('environment');

        info("Executing landlord:sync steps...");

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
