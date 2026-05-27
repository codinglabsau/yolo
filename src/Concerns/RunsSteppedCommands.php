<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Str;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Manifest;
use Laravel\Prompts\Progress;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\HasSubSteps;
use Codinglabs\Yolo\Contracts\RunsOnBuild;
use Codinglabs\Yolo\Contracts\ExecutesTenantStep;
use Codinglabs\Yolo\Contracts\ExecutesCommandStep;
use Codinglabs\Yolo\Steps\ExecuteBuildCommandStep;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\progress;

trait RunsSteppedCommands
{
    use ChecksIfCommandsShouldBeRunning;

    /**
     * Collate, plan, confirm and execute a set of scope-grouped steps as a single flow.
     *
     * @param  array<string, array<int, class-string>>  $scopes  ordered label => step class names
     */
    protected function runScopes(string $environment, array $scopes): int
    {
        Prompt::interactive($this->input->isInteractive());

        [$plan, $skipped] = $this->collateSteps($scopes, $environment);

        if ($plan->isEmpty() && $skipped->isEmpty()) {
            warning('No steps detected.');

            return SymfonyCommand::SUCCESS;
        }

        $this->printDeterminations($environment, $plan, $skipped);

        if (! $this->confirmGate($environment)) {
            warning('Aborted — no changes made.');

            return SymfonyCommand::SUCCESS;
        }

        $now = time();

        $ran = $this->executePlan($plan, $now);

        $this->renderResults($environment, $ran, $skipped, time() - $now);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Expand and partition every scope's steps into a runnable plan and a skipped ledger.
     *
     * @param  array<string, array<int, class-string>>  $scopes
     * @return array{0: Collection<int, array{scope: string, step: Step}>, 1: Collection<int, array{scope: string, step: Step, reason: string}>}
     */
    protected function collateSteps(array $scopes, string $environment): array
    {
        $plan = collect();
        $skipped = collect();

        foreach ($scopes as $label => $stepNames) {
            foreach ($stepNames as $stepName) {
                foreach ($this->expandStep(new $stepName($environment), $environment) as $step) {
                    $reason = $this->skipReason($step);

                    if ($reason === null) {
                        $plan->push(['scope' => $label, 'step' => $step]);
                    } else {
                        $skipped->push(['scope' => $label, 'step' => $step, 'reason' => $reason]);
                    }
                }
            }
        }

        return [$plan, $skipped];
    }

    protected function printDeterminations(string $environment, Collection $plan, Collection $skipped): void
    {
        intro(sprintf('Planning %s for %s', $this->getName(), $environment));

        $this->output->writeln('  <options=bold>Will sync</>');

        $plan->groupBy('scope')->each(function (Collection $entries, string $scope) {
            $this->output->writeln(sprintf('  <fg=green>✔</> %s <fg=gray>(%d)</>', $scope, $entries->count()));
        });

        if ($skipped->isNotEmpty()) {
            $this->output->writeln('');
            $this->output->writeln('  <options=bold>Skipping</>');

            $skipped
                ->groupBy(fn (array $entry) => $entry['scope'] . '|' . $entry['reason'])
                ->each(function (Collection $group) {
                    $first = $group->first();

                    $this->output->writeln(sprintf(
                        '  <fg=yellow>•</> %s <fg=gray>(%d)</> — %s',
                        $first['scope'],
                        $group->count(),
                        $first['reason'],
                    ));
                });
        }

        $this->output->writeln('');
    }

    protected function confirmGate(string $environment): bool
    {
        if ($this->option('force') || $this->option('dry-run') || ! $this->input->isInteractive()) {
            return true;
        }

        return confirm(
            label: sprintf('Apply these changes to %s?', $environment),
            default: false,
        );
    }

    /**
     * @param  Collection<int, array{scope: string, step: Step}>  $plan
     * @return Collection<int, array{index: int, scope: string, step: Step, status: StepResult|string, elapsed: int}>
     */
    protected function executePlan(Collection $plan, int $now): Collection
    {
        $multiScope = $plan->pluck('scope')->unique()->count() > 1;

        $progress = $this->option('no-progress')
            ? null
            : progress(label: 'Starting first step...', steps: $plan->count());

        $progress?->start();

        $ran = $plan->values()->map(function (array $entry, int $i) use ($progress, $now, $multiScope) {
            $step = $entry['step'];

            $label = $multiScope
                ? sprintf('%s · %s', $entry['scope'], static::normaliseStep($step))
                : static::normaliseStep($step);

            return [
                'index' => $i + 1,
                'scope' => $entry['scope'],
                'step' => $step,
                ...$this->invokeStep($step, $progress, $label, $now),
            ];
        });

        $progress?->finish();

        return $ran;
    }

    /**
     * Render the progress frame for a step, invoke it, and time it.
     *
     * @return array{status: StepResult|string, elapsed: int, changes: array<int, Change>}
     */
    protected function invokeStep(Step $step, ?Progress $progress, string $label, int $now): array
    {
        $progress?->label($label)
            ->hint(sprintf('%d seconds elapsed', time() - $now))
            ->render();

        $started = time();

        $status = $step->__invoke($this->input->getOptions(), $this);

        // Build steps return void, and HasSubSteps steps return their sub-step
        // array (load-bearing for expandStep) — neither is a StepResult. Steps
        // signal failure by throwing, so a step that returned at all succeeded:
        // render it as such rather than handing renderStatus a non-StepResult.
        if (! $status instanceof StepResult && ! is_string($status)) {
            $status = StepResult::SUCCESS;
        }

        $progress?->advance();

        return [
            'status' => $status,
            'elapsed' => time() - $started,
            // Steps that reconcile config (directly, or via SynchronisesResource)
            // record the attributes they changed so the Changes report can surface
            // each current → desired comparison.
            'changes' => method_exists($step, 'changes') ? $step->changes() : [],
        ];
    }

    /**
     * @param  Collection<int, array{index: int, scope: string, step: Step, status: StepResult|string, elapsed: int}>  $ran
     * @param  Collection<int, array{scope: string, step: Step, reason: string}>  $skipped
     */
    protected function renderResults(string $environment, Collection $ran, Collection $skipped, int $elapsed): void
    {
        $multiScope = $ran->pluck('scope')->unique()->count() > 1;

        $lastScope = null;

        $rows = $ran->map(function (array $result) use (&$lastScope, $multiScope) {
            $row = [$result['index']];

            if ($multiScope) {
                $row[] = $result['scope'] === $lastScope ? '' : $result['scope'];
                $lastScope = $result['scope'];
            }

            $row[] = static::normaliseStep($result['step'], pad: true, bold: true, arrow: true);
            $row[] = static::renderStatus($result['status']);
            $row[] = sprintf('%ds', $result['elapsed']);

            return $row;
        });

        if ($rows->isNotEmpty()) {
            table(
                $multiScope
                    ? ['#', 'Scope', 'Step', 'Status', 'Elapsed']
                    : ['#', 'Step', 'Status', 'Elapsed'],
                $rows->all()
            );
        }

        $this->renderChanges($ran, $multiScope);

        if ($skipped->isNotEmpty()) {
            if ($this->output->isVerbose()) {
                table(
                    ['Scope', 'Step', 'Reason'],
                    $skipped->map(fn (array $entry) => [
                        $entry['scope'],
                        static::normaliseStep($entry['step']),
                        $entry['reason'],
                    ])->all()
                );
            } else {
                info(sprintf(
                    '%d step%s skipped (run with -v to show).',
                    $skipped->count(),
                    $skipped->count() === 1 ? '' : 's',
                ));
            }
        }

        info(sprintf('Synced %s in %ds.', $environment, $elapsed));
    }

    /**
     * Print the per-attribute detail behind the status column: which attributes
     * each step reconciled (or, under --dry-run, would reconcile), as a
     * current → desired comparison. Steps that changed nothing are omitted, so a
     * clean sync stays quiet and drift stands out.
     *
     * @param  Collection<int, array{scope: string, step: Step, status: StepResult|string, changes: array<int, Change>}>  $ran
     */
    protected function renderChanges(Collection $ran, bool $multiScope): void
    {
        $withChanges = $ran->filter(fn (array $result) => $result['changes'] !== []);

        if ($withChanges->isEmpty()) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln(sprintf(
            '  <options=bold>%s</>',
            $this->option('dry-run') ? 'Pending changes' : 'Changes applied',
        ));

        $withChanges->each(function (array $result) use ($multiScope) {
            $label = $multiScope
                ? sprintf('%s · %s', $result['scope'], static::normaliseStep($result['step']))
                : static::normaliseStep($result['step']);

            $this->output->writeln(sprintf('  <fg=cyan>%s</>', $label));

            foreach ($result['changes'] as $change) {
                $this->output->writeln(sprintf(
                    '    %s: <fg=red>%s</> <fg=gray>→</> <fg=green>%s</>',
                    $change->attribute,
                    $change->from ?? 'absent',
                    $change->to ?? 'absent',
                ));
            }
        });
    }

    protected function handleSteps(string $environment): int
    {
        $now = time();

        $steps = $this->extractSteps($environment);

        if (count($steps) === 0) {
            warning('No steps detected');

            return time() - $now;
        }

        $progress = $this->option('no-progress')
            ? null
            : progress(
                label: 'Starting first step...',
                steps: count($steps)
            );

        $progress?->start();

        $output = $steps->map(function (Step $step, int $i) use ($progress, $now) {
            ['status' => $status, 'elapsed' => $elapsed] = $this->invokeStep($step, $progress, static::normaliseStep($step), $now);

            return [
                $i + 1,
                static::normaliseStep($step, pad: true, bold: true, arrow: true),
                static::renderStatus($status),
                $elapsed,
            ];
        });

        $progress?->finish();

        table(
            ['Step', 'Description', 'Status', 'Elapsed'],
            $output->map(fn ($step) => [
                $step[0],
                $step[1],
                $step[2],
                sprintf('%ds', number_format($step[3])),
            ])
        );

        return $output->sum(fn ($step) => $step[3]);
    }

    protected function extractSteps(string $environment): Collection
    {
        return collect($this->steps)
            ->flatMap(fn (string $stepName) => $this->expandStep(new $stepName($environment), $environment))
            ->filter(fn (Step $step) => $this->shouldBeRunning($step));
    }

    /**
     * Expand a step into its concrete runnable instances (sub-steps and per-tenant clones).
     *
     * @return array<int, Step>
     */
    protected function expandStep(Step $step, string $environment): array
    {
        if ($step instanceof HasSubSteps) {
            return collect($step->__invoke())
                ->map(fn (string $subStepName) => match (true) {
                    $step instanceof RunsOnBuild => new ExecuteBuildCommandStep($environment, $subStepName),
                })
                ->prepend($step)
                ->all();
        }

        if ($step instanceof ExecutesTenantStep) {
            return collect($this->tenantsToExpand())
                ->map(function (array $config, string $tenantId) use ($step) {
                    $clone = clone $step;

                    $clone->setTenantId($tenantId)
                        ->setConfig($config);

                    return $clone;
                })->values()->all();
        }

        return [$step];
    }

    /**
     * The tenants per-tenant steps fan out over — every manifest tenant, narrowed
     * to one when the command exposes `--tenant` and it's set (a single-tenant
     * cutover). Commands without the option (start/build) get the full set.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function tenantsToExpand(): array
    {
        $tenants = Manifest::tenants();

        if (! $this->getDefinition()->hasOption('tenant') || ($only = $this->option('tenant')) === null) {
            return $tenants;
        }

        if (! array_key_exists($only, $tenants)) {
            throw new IntegrityCheckException(sprintf('Unknown tenant "%s" — not defined in the manifest.', $only));
        }

        return [$only => $tenants[$only]];
    }

    protected static function renderStatus(StepResult|string $status): string
    {
        return match ($status) {
            // green
            StepResult::CREATED => '<fg=green>CREATED</>',
            StepResult::SUCCESS => '<fg=green>SUCCESS</>',
            StepResult::SYNCED => '<fg=green>SYNCED</>',

            // yellow
            StepResult::SKIPPED => '<fg=yellow>SKIPPED</>',
            StepResult::CUSTOM_MANAGED => '<fg=yellow>CUSTOM MANAGED</>',
            StepResult::WOULD_CREATE => '<fg=yellow>WOULD CREATE</>',
            StepResult::WOULD_SYNC => '<fg=yellow>WOULD SYNC</>',

            // red
            StepResult::MANIFEST_INVALID => '<fg=red>MANIFEST INVALID</>',
            StepResult::TIMEOUT => '<fg=red>TIMEOUT</>',
            default => is_string($status) ? $status : '',
        };
    }

    protected static function normaliseStep(Step $step, $pad = false, $bold = false, $arrow = false): string
    {
        $name = match (true) {
            $step instanceof ExecutesCommandStep => Str::of($step->name())
                ->when($arrow, fn (Stringable $string) => $string->prepend($arrow ? ' ➡ ' : '')),
            default => Str::of(get_class($step))
                ->classBasename()
                ->replaceLast('Step', '')
                ->headline()
                ->lower()
                ->ucfirst()
                ->when($step instanceof ExecutesTenantStep, fn (Stringable $string) => $string->prepend('[' . $step->tenantId() . '] '))
                ->when($bold && ! $step instanceof ExecutesTenantStep, fn (Stringable $string) => $string->wrap(before: '<options=bold>', after: '</>'))
        };

        return $name->limit(70)
            ->when($pad, fn (Stringable $string) => $string->padRight(70));
    }
}
