<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;

class ComputeSyncCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Compute\SyncLaunchTemplateStep::class,
        Steps\Compute\SyncElasticTranscoderPipelineStep::class,
        Steps\Compute\SyncElasticTranscoderPresetStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('compute:sync')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->setDescription('Sync configured compute AWS resources');
    }

    public function handle(): void
    {
        if (Aws::runningInAws()) {
            error("compute:sync command cannot be run in AWS.");
            return;
        }

        $environment = $this->argument('environment');

        info("Executing compute:sync steps...");

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
