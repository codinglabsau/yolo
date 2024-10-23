<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;

class BuildCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Build\PurgeBuildStep::class,
        Steps\Build\RetrieveEnvFileStep::class,
        Steps\Build\CopyApplicationStep::class,
        Steps\Build\ConfigureEnvAndVersionStep::class,
        Steps\Build\CreateTemporaryEnvStep::class,
        Steps\Build\ExecuteBuildStepsStep::class,
        Steps\Build\RestoreTemporaryEnvStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('build')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'The app version to tag the build with')
            ->setDescription('Prepare a build of the application for deployment');
    }

    public function handle(): void
    {
        if (Aws::runningInAws()) {
            error("build command cannot be run in AWS.");
            return;
        }

        $appVersion = $this->option('app-version') ?? date('y.W.N.Hi');

        if (! str_starts_with($appVersion, date('y.W'))) {
            error(sprintf("App version must start with %s", date('y.W')));
            return;
        }

        $this->input->setOption('app-version', $appVersion);

        intro("Building app version: {$appVersion}");

        $environment = $this->argument('environment');

        info("Executing build steps...");

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
