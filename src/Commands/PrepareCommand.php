<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;

class PrepareCommand extends Command
{
    use RunsSteppedCommands;
    use UsesEc2;

    protected array $steps = [
        // create new launch template version; requires the specified AMI ID
        Steps\Ami\CreateLaunchTemplateVersionStep::class,

        // scheduler group
        Steps\Ami\CreateAutoScalingSchedulerGroupStep::class,

        // queue group
        Steps\Ami\CreateAutoScalingQueueGroupStep::class,

        // web group
        Steps\Ami\CreateAutoScalingWebGroupStep::class,
        Steps\Ami\CreateWebGroupCpuAlarmsStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('prepare')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('ami-id', null, InputOption::VALUE_REQUIRED, 'The AMI ID to prepare for service')
            ->setDescription('Prepare a new deployment group');
    }

    public function handle(): void
    {
        if (Aws::runningInAws()) {
            error("ami:prepare command cannot be run in AWS.");
            return;
        }

        $environment = $this->argument('environment');

        info("Executing build steps...");

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
