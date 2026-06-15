<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\CostExplorer;
use Codinglabs\Yolo\Contracts\ReadOnlyCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use function Laravel\Prompts\intro;

/**
 * Month-to-date spend for the app against its declared budget — the cost read
 * surface. YOLO never enforces a budget (it never acts); this reports spend, the
 * declared cap and the `budget.strategy`, and the `/yolo` skill weights its
 * recommendations by them. Spend comes from Cost Explorer via the `yolo:app`
 * cost-allocation tag, so it shows "—" until that tag is activated in Billing.
 */
class StatusBudgetCommand extends Command implements ReadOnlyCommand
{
    protected function configure(): void
    {
        $this
            ->setName('status:budget')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the budget state as JSON and exit (machine-readable; for the /yolo skill and scripts)')
            ->setDescription("Show the app's month-to-date spend against its declared budget");
    }

    public function handle(): int
    {
        $spend = CostExplorer::monthToDateByApp(Manifest::name());
        $amount = Manifest::get('budget.amount');
        $amount = $amount === null ? null : (float) $amount;
        $strategy = (string) (Manifest::get('budget.strategy') ?? 'balanced');

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'app' => Manifest::current()['name'] ?? null,
                'environment' => $this->argument('environment'),
                'currency' => 'USD',
                'spend' => $spend,
                'budget' => ['amount' => $amount, 'strategy' => $strategy],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        intro(sprintf('yolo status:budget · %s · %s', Manifest::current()['name'] ?? '', $this->argument('environment')));

        $this->output->writeln('  ' . static::formatBudget($spend, $amount, $strategy));

        return self::SUCCESS;
    }

    /**
     * `$42.10 / $100.00 · 42% · strategy: balanced`, or `… · no budget set …`
     * when no cap is declared, or a `—` spend when Cost Explorer has no data.
     * Pure — unit-tested directly.
     */
    public static function formatBudget(?float $spend, ?float $amount, string $strategy): string
    {
        $spendLabel = $spend === null ? '<fg=gray>—</>' : '$' . number_format($spend, 2);

        if ($amount === null) {
            return sprintf('%s spent this month · <fg=gray>no budget set</> · strategy: %s', $spendLabel, $strategy);
        }

        $percent = $amount > 0.0 && $spend !== null ? sprintf(' · %d%%', (int) round($spend / $amount * 100)) : '';

        return sprintf('%s / $%s%s · strategy: %s', $spendLabel, number_format($amount, 2), $percent, $strategy);
    }
}
