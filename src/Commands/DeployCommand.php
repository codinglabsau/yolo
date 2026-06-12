<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\intro;

class DeployCommand extends SteppedCommand
{
    use RendersServiceStatus;

    protected array $steps = [
        // Republish the app's claim file first — claims must lead the code
        // that consumes a service, and a deploy against an environment that
        // was never synced fails fast here with instructions.
        Steps\Sync\App\PublishAppManifestStep::class,
        Steps\Deploy\PushAssetsToS3Step::class,
        Steps\Deploy\RegisterTaskDefinitionRevisionStep::class,
        Steps\Deploy\ExecuteDeployStepsStep::class,
        Steps\Deploy\UpdateEcsServiceStep::class,
        Steps\Deploy\WaitForDeploymentHealthyStep::class,
        Steps\Deploy\SyncSoloRecordSetStep::class,
        Steps\Deploy\SyncMultitenancyRecordSetStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('deploy')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('app-version', null, InputArgument::OPTIONAL, 'Tag to stamp on the build (defaults to a timestamp)')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Comma-separated service groups to roll (web,queue,scheduler) — defaults to all the app runs')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Build, push, and deploy the application');
    }

    #[\Override]
    public function handle(): int
    {
        $build = (new BuildCommand())->execute($this->input, $this->output);

        if ($build !== self::SUCCESS) {
            return $build;
        }

        intro("Deploying app version: {$this->option('app-version')}");

        $result = parent::handle();

        if ($result === self::SUCCESS) {
            $this->renderDeploymentSummary();
        }

        return $result;
    }

    /**
     * Recap what's now running once the rollout has settled — the same summary
     * table and CloudWatch dashboard link `yolo status` shows, minus the live
     * deployment/load panels (the deploy just finished, so there's nothing in
     * flight and load hasn't built up yet).
     */
    protected function renderDeploymentSummary(): void
    {
        intro('Deployment summary');

        foreach ($this->statusLines(static::gatherServiceStatuses(withLoad: false), time(), deployments: false, load: false) as $line) {
            $this->output->writeln($line);
        }
    }
}
