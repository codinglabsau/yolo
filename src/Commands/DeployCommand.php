<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class DeployCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Ensures\EnsureTranscoderExistsStep::class,
        Steps\Ensures\EnsureHostedZonesExistStep::class,
        Steps\Ensures\EnsureTenantHostedZonesExistStep::class,
        Steps\Ensures\EnsureEnvIsConfiguredCorrectlyStep::class,
        Steps\Ensures\EnsureAutoscalingGroupSchedulerExistsStep::class,
        Steps\Ensures\EnsureAutoscalingGroupQueueExistsStep::class,
        Steps\Ensures\EnsureAutoscalingGroupWebExistsStep::class,
        Steps\Deploy\CreateArtefactStep::class,
        Steps\Deploy\PushArtefactToS3Step::class,
        Steps\Deploy\PushAssetsToS3Step::class,
        Steps\Deploy\UpdateCodeDeployDeploymentGroupStep::class,
        Steps\Deploy\CreateCodeDeployDeploymentsStep::class,
        Steps\Build\PurgeBuildStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('deploy')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'The app version to tag the build with')
            ->setDescription('Deploy a build of the application to AWS');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        if (Aws::runningInAws()) {
            error("You can't run the deploy command from an AWS instance.");
            return;
        }

        $reuseBuild = false;

        if (is_dir(Paths::yolo())) {
            $reuseBuild = confirm('Yolo build already exists; do you want to re-use the existing build?');
        }

        if (! $reuseBuild) {
            warning("Building fresh version...");

            (new BuildCommand())->execute(Helpers::app('input'), Helpers::app('output'));
        }

        info("Executing deploy steps...");

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
