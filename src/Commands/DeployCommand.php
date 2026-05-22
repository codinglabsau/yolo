<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\intro;

class DeployCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Deploy\PushAssetsToS3Step::class,
        Steps\Deploy\RegisterTaskDefinitionRevisionStep::class,
        Steps\Deploy\ExecuteDeployStepsStep::class,
        Steps\Deploy\UpdateEcsServiceStep::class,
        Steps\Deploy\WaitForServiceStableStep::class,
        Steps\Deploy\SyncSoloRecordSetStep::class,
        Steps\Deploy\SyncMultitenancyRecordSetStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('deploy')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'Tag to stamp on the build (defaults to a timestamp)')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Build, push, and deploy the application');
    }

    public function handle(): int
    {
        $build = (new BuildCommand())->execute($this->input, $this->output);

        if ($build !== self::SUCCESS) {
            return $build;
        }

        intro("Deploying app version: {$this->option('app-version')}");

        return parent::handle();
    }
}
