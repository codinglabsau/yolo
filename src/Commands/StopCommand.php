<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;

class StopCommand extends Command implements RunsOnAws
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Stop\StopWorkOnQueueStep::class,
        Steps\Stop\StopWorkOnSchedulerStep::class,
        Steps\Stop\StopWorkOnWebStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('stop')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Stop work before deployment');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        info("Executing stop steps...");

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
