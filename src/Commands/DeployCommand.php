<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Contracts\DeployerCommand;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\intro;

class DeployCommand extends SteppedCommand implements DeployerCommand
{
    use RendersServiceStatus;

    protected array $steps = [
        // Claims must lead the code that consumes a service.
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
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Run under the admin tier (MFA-gated) so a drifted environment can be reconciled inline instead of refusing')
            ->setDescription('Build, push, and deploy the application');
    }

    /**
     * `--admin` lets the in-sync gate reconcile drift inline rather than refusing —
     * the deployer tier can't write the shared foundation drift touches. Input may
     * not be bound yet under direct unit invocation.
     */
    #[\Override]
    protected function awsTier(): ?Iam
    {
        $admin = isset($this->input)
            && $this->input->hasOption('admin')
            && (bool) $this->input->getOption('admin');

        return $admin ? Iam::ADMIN_ROLE : Iam::DEPLOYER_ROLE;
    }

    #[\Override]
    public function handle(): int
    {
        // Before the build, so a drifted environment fails fast without burning one.
        (new Steps\Deploy\EnsureInSyncStep(admin: (bool) $this->option('admin')))([]);

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

    protected function renderDeploymentSummary(): void
    {
        intro('Deployment summary');

        foreach ($this->statusLines(static::gatherServiceStatuses(withLoad: false), time(), deployments: false, load: false) as $line) {
            $this->output->writeln($line);
        }

        foreach ($this->appUrlLines() as $line) {
            $this->output->writeln($line);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function appUrlLines(): array
    {
        // Not Manifest::tenants() — that derives each apex via a Route 53 suffix
        // walk, and printing URLs needs no AWS reads.
        $domains = array_values(array_unique([
            ...array_filter([Manifest::domain()]),
            ...Manifest::tenantDomains(),
        ]));

        if ($domains === []) {
            return [];
        }

        return ['', ...array_map(
            fn (string $domain): string => sprintf('  <options=bold>Live</> <href=https://%s>https://%s</>', $domain, $domain),
            $domains,
        )];
    }
}
