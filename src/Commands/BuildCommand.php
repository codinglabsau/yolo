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

        // since the date uses the ISO week number and year, we have to format the year to 'o' and then extract the last two digits
        $appVersion = $this->option('app-version') ?? substr(date('o'), -2) . '.' . date('W.N.Hi');

        $date = now()->timezone('Australia/Brisbane');
        $isoYear = $date->format('o');
        $shortYear = substr($isoYear, -2);
        $isoWeek = $date->format("W");

        $requiredPrefix = "{$shortYear}.{$isoWeek}";

        if (! str_starts_with($appVersion, $requiredPrefix)) {
            error(sprintf("App version must start with %s, the version provided was %s", $requiredPrefix, $appVersion));
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
