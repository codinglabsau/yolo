<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

class SyncCiCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Ci\SyncCodeDeployApplicationStep::class,
//        Steps\Ci\SyncCodeDeployDeploymentConfigStep::class, // todo: drop this once we have an Envoyer style deployment
        Steps\Ci\SyncCodeDeploySchedulerDeploymentGroupStep::class,
        Steps\Ci\SyncCodeDeployQueueDeploymentGroupStep::class,
        Steps\Ci\SyncCodeDeployWebDeploymentGroupStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:ci')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->setDescription('Sync continuous integration AWS resources');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        intro(sprintf("Executing sync:ci steps in %s", $environment));

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
