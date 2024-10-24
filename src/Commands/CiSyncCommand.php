<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

class CiSyncCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Ci\SyncCodeDeployApplicationStep::class,
        Steps\Ci\SyncCodeDeployDeploymentConfigStep::class,
        Steps\Ci\SyncCodeDeploySchedulerDeploymentGroupStep::class,
        Steps\Ci\SyncCodeDeployQueueDeploymentGroupStep::class,
        Steps\Ci\SyncCodeDeployWebDeploymentGroupStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('ci:sync')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->setDescription('Sync configured ci AWS resources');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        intro(sprintf("Executing ci:sync steps in %s", $environment));

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
