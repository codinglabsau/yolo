<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

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
        $environment = $this->argument('environment');

        intro(sprintf("Executing landlord:sync steps in %s", $environment));

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
