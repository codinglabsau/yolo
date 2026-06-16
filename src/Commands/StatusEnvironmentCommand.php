<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Aws\CostExplorer;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Codinglabs\Yolo\Contracts\ReadsEnvironment;
use Symfony\Component\Console\Input\InputOption;
use Codinglabs\Yolo\Concerns\RendersServiceStatus;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

/**
 * The env-tier status roll-up — a compact health row per app in the environment
 * (each app's web service: task counts, rollout state and version), discovered
 * from the live ECS clusters in the env's namespace. The per-app detail (load,
 * scaling, queues) is `status` / `status:app`.
 */
class StatusEnvironmentCommand extends Command implements ReadOnlyCommand, ReadsEnvironment
{
    use RendersServiceStatus;

    protected function configure(): void
    {
        $this
            ->setName('status:environment')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the roll-up as JSON and exit (machine-readable; for the /yolo skill and scripts)')
            ->setDescription("Roll up every app's status across an environment");
    }

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $rows = static::gatherEnvStatuses($environment);
        $budget = static::gatherEnvBudget($environment);

        // `--json` is the machine-readable contract: emit the roll-up and exit
        // non-zero if any app has a failed deploy so it stays scriptable.
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'environment' => $environment,
                'apps' => static::jsonEnvStatuses($rows),
                'budget' => $budget,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return static::anyDeploymentFailed($rows) ? 1 : 0;
        }

        if ($rows === []) {
            info(sprintf("No live apps in '%s'.", $environment));

            return self::SUCCESS;
        }

        intro(sprintf('yolo status · %s · all apps', $environment));

        foreach ($this->envRollupLines($rows) as $line) {
            $this->output->writeln($line);
        }

        // The env-tier half of the two-tier budget: total spend across the env
        // (all apps + shared infra, via the yolo:environment tag) vs the cap
        // declared in the env manifest. Shown only when there's something to show.
        if ($budget['amount'] !== null || $budget['spend'] !== null) {
            $this->output->writeln('');
            $this->output->writeln('  <options=bold>Budget</> <fg=gray>(env, month-to-date)</>');
            $this->output->writeln('  ' . StatusBudgetCommand::formatBudget($budget['spend'], $budget['amount'], $budget['strategy']));
        }

        return static::anyDeploymentFailed($rows) ? 1 : 0;
    }

    /**
     * The env-tier budget: the cap + strategy declared in the env manifest, plus
     * month-to-date spend across the whole environment (Cost Explorer via the
     * `yolo:environment` tag). Mirrors the app-tier shape `status:budget` emits.
     *
     * @return array{currency: string, amount: ?float, strategy: string, spend: ?float}
     */
    protected static function gatherEnvBudget(string $environment): array
    {
        $amount = EnvManifest::get('budget.amount');

        return [
            'currency' => 'USD',
            'amount' => $amount === null ? null : (float) $amount,
            'strategy' => (string) (EnvManifest::get('budget.strategy') ?? 'balanced'),
            'spend' => CostExplorer::monthToDateByEnvironment($environment),
        ];
    }
}
