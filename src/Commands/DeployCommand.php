<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;

class DeployCommand extends SteppedCommand
{
    protected array $steps = [
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
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'The app version to deploy (defaults to the last build)')
            ->addOption('watch', null, null, 'Wait for the ECS service to reach a steady state')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Deploy the latest build to the given environment');
    }

    public function handle(): int
    {
        if (! Manifest::has('tasks.web')) {
            error('`yolo deploy` requires a `tasks.web` block in yolo.yml — this is the Fargate deploy path.');

            return self::FAILURE;
        }

        $appVersion = $this->option('app-version') ?? $this->lastBuiltVersion();

        if (! $appVersion) {
            error('No app version specified and no build artefact found. Run `yolo build` first.');

            return self::FAILURE;
        }

        $this->input->setOption('app-version', $appVersion);

        intro("Deploying app version: {$appVersion}");

        return parent::handle();
    }

    protected function lastBuiltVersion(): ?string
    {
        if (! file_exists(Paths::version())) {
            return null;
        }

        return trim(file_get_contents(Paths::version()));
    }
}
