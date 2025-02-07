<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Helpers;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class DeployCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Ensures\EnsureTranscoderExistsStep::class,
        Steps\Ensures\EnsureHostedZonesExistStep::class,
        Steps\Ensures\EnsureMultitenancyHostedZonesExistStep::class,
        Steps\Ensures\EnsureEnvIsConfiguredCorrectlyStep::class,
        Steps\Ensures\EnsureAutoscalingGroupSchedulerExistsStep::class,
        Steps\Ensures\EnsureAutoscalingGroupQueueExistsStep::class,
        Steps\Ensures\EnsureAutoscalingGroupWebExistsStep::class,
        Steps\Deploy\CreateArtefactStep::class,
        Steps\Deploy\PushArtefactToS3Step::class,
        Steps\Deploy\PushAssetsToS3Step::class,
        Steps\Deploy\UpdateCodeDeployDeploymentGroupStep::class,
        Steps\Deploy\CreateCodeDeployDeploymentsStep::class,
//        Steps\Deploy\SyncStandaloneRecordSetStep::class, // todo: temp
//        Steps\Deploy\SyncMultitenancyRecordSetStep::class, // todo: temp
        Steps\Build\PurgeBuildStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('deploy')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'The app version to tag the build with')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Deploy a build of the application to AWS');
    }

    public function handle(): void
    {
        $reuseBuild = false;

        if (is_dir(Paths::yolo())) {
            $reuseBuild = confirm('Yolo build already exists; do you want to re-use the existing build?');
        }

        if (! $reuseBuild) {
            warning("Building fresh version...");

            (new BuildCommand())->execute(Helpers::app('input'), Helpers::app('output'));
        }

        parent::handle();
    }
}
