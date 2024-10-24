<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;

class AmiCreateCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Ensures\EnsureLaunchTemplateExistsStep::class,
        Steps\Ami\LaunchAmiInstanceStep::class,
        Steps\Ami\WaitForUserDataToExecuteStep::class,
        Steps\Ensures\EnsurePhpInstalledStep::class,
        Steps\Ensures\EnsureSwooleInstalledStep::class,
        Steps\Ensures\EnsureNodeInstalledStep::class,
        Steps\Ensures\EnsureNginxInstalledStep::class,
        Steps\Ami\StopAmiInstanceStep::class,
        Steps\Ami\CreateAmiStep::class,
        Steps\Ami\TerminateAmiInstanceStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('ami:create')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Prepare a new Amazon Machine Image');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        info("Executing build steps...");

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
