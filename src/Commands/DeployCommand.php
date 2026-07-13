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
        // Republish the app's claim file first — claims must lead the code that
        // consumes a service. (A deploy against an unsynced environment is already
        // refused up front by the EnsureInSyncStep gate in handle(); this step's
        // job here is the republish itself, not the fail-fast.)
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
     * A deploy runs under the least-privilege Deployer tier by default. `--admin`
     * caps it up to the Admin tier instead (minted MFA-gated up front, like `sync`),
     * so the in-sync gate can reconcile a drifted environment inline rather than
     * refusing — the deployer tier can't write the shared foundation drift touches.
     * Guarded against input not yet bound (direct unit invocation), mirroring
     * skipsPermissions().
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
        // Refuse to deploy into an environment that has drifted from its declared
        // state — runs the full `sync --check` plan before the build, so a drifted
        // environment fails fast without burning one. Under `--admin` the gate
        // reconciles the drift inline (admin holds the writes); otherwise it throws.
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

        foreach ($this->appUrlLines() as $line) {
            $this->output->writeln($line);
        }
    }

    /**
     * The freshly deployed app's public URL(s) — the "go visit it" link the
     * summary ends on. Solo apps have the one manifest domain; multi-tenant
     * apps list every tenant's. Headless apps (no domain) get no line.
     *
     * @return array<int, string>
     */
    protected function appUrlLines(): array
    {
        // Raw tenant config, not Manifest::tenants() — that derives each apex via
        // the Route 53 suffix walk, and printing URLs needs no AWS reads.
        $domains = Manifest::isMultitenanted()
            ? collect(Manifest::get('tenants'))->pluck('domain')->filter()->values()->all()
            : array_filter([Manifest::get('domain')]);

        if ($domains === []) {
            return [];
        }

        return ['', ...array_map(
            fn (string $domain): string => sprintf('  <options=bold>Live</> <href=https://%s>https://%s</>', $domain, $domain),
            $domains,
        )];
    }
}
