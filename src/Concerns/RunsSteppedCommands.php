<?php

namespace Codinglabs\Yolo\Concerns;

use Spatie\Fork\Fork;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Str;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Laravel\Prompts\Progress;
use Codinglabs\Yolo\Destroying;
use Codinglabs\Yolo\WaitReporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\HasSubSteps;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Contracts\RunsOnBuild;
use Codinglabs\Yolo\Contracts\PlansSequentially;
use Codinglabs\Yolo\Contracts\ExecutesTenantStep;
use Codinglabs\Yolo\Contracts\ExecutesCommandStep;
use Codinglabs\Yolo\Steps\ExecuteBuildCommandStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\progress;

trait RunsSteppedCommands
{
    use ChecksIfCommandsShouldBeRunning;

    /**
     * Approve-before-apply: a dry-run plan pass, the confirm gate, then apply over
     * only the steps the plan flagged as pending. Clean steps are dropped after
     * plan so apply doesn't repeat their Describe* round-trips or re-tag them.
     *
     * @param  array<string, array<int, class-string>>  $scopes  ordered label => step class names
     */
    protected function runScopes(string $environment, array $scopes): int
    {
        Prompt::interactive($this->input->isInteractive());

        [$planned, $skipped] = $this->collateSteps($scopes, $environment);

        if ($planned->isEmpty() && $skipped->isEmpty()) {
            warning('No steps detected.');

            return SymfonyCommand::SUCCESS;
        }

        intro(sprintf('Planning %s for %s', $this->getName(), $environment));

        $plan = $this->executePlan($planned, time(), apply: false);

        $this->printPlan($plan, $skipped);

        $pending = $plan->filter(fn (array $entry): bool => static::planEntryHasWork($entry))->values();

        if (! $this->ensureCliMayApply($pending)) {
            return SymfonyCommand::FAILURE;
        }

        // --check is a CI gate: exit non-zero on drift so a pipeline can fail on unsynced infra.
        if ($this->option('check')) {
            if ($pending->isNotEmpty()) {
                warning(sprintf('Drift detected — %s has %d pending change(s).', $environment, $pending->count()));

                return SymfonyCommand::FAILURE;
            }

            info(sprintf('In sync — %s has no pending changes.', $environment));

            return SymfonyCommand::SUCCESS;
        }

        if ($pending->isEmpty()) {
            info(sprintf('Already in sync — %s has no pending changes.', $environment));

            return SymfonyCommand::SUCCESS;
        }

        if (! $this->confirmGate($environment)) {
            warning('🐥 No changes made.');

            return SymfonyCommand::SUCCESS;
        }

        $applyPlan = $pending->map(fn (array $entry): array => [
            'scope' => $entry['scope'],
            'step' => $entry['step'],
        ])->values();

        // Step instances are reused across passes.
        $this->resetRecordedState($applyPlan);

        $now = time();

        $applied = $this->executePlan($applyPlan, $now, apply: true);

        $this->renderResults($environment, $applied, time() - $now);

        $this->renderDeferredWarnings($applied);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @param  array{status: StepResult|string, changes: array<int, Change>}  $entry
     */
    protected static function planEntryHasWork(array $entry): bool
    {
        return in_array($entry['status'], [StepResult::WOULD_CREATE, StepResult::WOULD_SYNC, StepResult::WOULD_DELETE, StepResult::WOULD_BUILD], true)
            || $entry['changes'] !== [];
    }

    /**
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

    /**
     * @param  Collection<int, array{index: int, scope: string, step: Step, status: StepResult|string, elapsed: int, changes: array<int, Change>, warnings: array<int, string>}>  $plan
     * @param  Collection<int, array{scope: string, step: Step, reason: string}>  $skipped
     */
    protected function printPlan(Collection $plan, Collection $skipped): void
    {
        $this->output->writeln(sprintf('  <options=bold>%s</>', $this->planHeading()));

        $plan->groupBy('scope')->each(function (Collection $entries, string $scope): void {
            $this->output->writeln(sprintf('  <fg=green>✔</> %s <fg=gray>(%d)</>', $scope, $entries->count()));
        });

        $multiScope = $plan->pluck('scope')->unique()->count() > 1;

        $this->renderWillCreate($plan, $multiScope);

        $this->renderPendingChanges($plan, $multiScope);

        $this->renderSkipping($skipped);

        $this->renderWarnings();

        $this->output->writeln('');
    }

    /**
     * Command-level advisories shown above the confirm gate — soft nudges, not gates.
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return [];
    }

    protected function renderWarnings(): void
    {
        $warnings = $this->warnings();

        if ($warnings === []) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('  <options=bold>Warnings</>');

        foreach ($warnings as $warning) {
            $this->output->writeln(sprintf('  <fg=yellow>• %s</>', $warning));
        }
    }

    protected function confirmGate(string $environment): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        return confirm(
            label: $this->confirmQuestion($environment),
        );
    }

    /**
     * A teardown command overrides this, {@see confirmQuestion()} and
     * {@see completionVerb()} so the flow reads as a destroy without duplicating the runner.
     */
    protected function planHeading(): string
    {
        return 'Will sync';
    }

    protected function confirmQuestion(string $environment): string
    {
        return sprintf('Apply these changes to %s?', $environment);
    }

    protected function completionVerb(): string
    {
        return 'Synced';
    }

    /**
     * The plan pass may fan out across forked workers because every step's plan
     * must already survive nothing-exists-yet, so none depends on a sibling having
     * run. Apply always runs sequentially — once writes start, declaration order
     * IS the dependency order. {@see PlansSequentially} opts out of the fan-out
     * because its steps make fork-unsafe AWS calls that can deadlock a worker.
     *
     * @param  Collection<int, array{scope: string, step: Step}>  $plan
     * @return Collection<int, array{index: int, scope: string, step: Step, status: StepResult|string, elapsed: int, changes: array<int, Change>, warnings: array<int, string>}>
     */
    protected function executePlan(Collection $plan, int $now, bool $apply): Collection
    {
        $options = $apply
            ? $this->input->getOptions()
            : [...$this->input->getOptions(), 'dry-run' => true];

        if (! $apply && ! $this instanceof PlansSequentially && static::planWorkers($plan->count()) > 1) {
            return $this->executePlanConcurrently($plan, $options);
        }

        $multiScope = $plan->pluck('scope')->unique()->count() > 1;

        $progress = $this->option('no-progress')
            ? null
            : progress(label: 'Starting first step...', steps: $plan->count());

        $progress?->start();

        $ran = $plan->values()->map(function (array $entry, int $i) use ($progress, $now, $multiScope, $options): array {
            $step = $entry['step'];

            $label = $multiScope
                ? sprintf('%s · %s', $entry['scope'], static::normaliseStep($step))
                : static::normaliseStep($step);

            return [
                'index' => $i + 1,
                'scope' => $entry['scope'],
                'step' => $step,
                ...$this->invokeStep($step, $progress, $label, $now, $options),
            ];
        });

        $progress?->finish();

        return $ran;
    }

    /**
     * Capped so a wide plan can't burst-throttle the AWS Describe APIs. The test
     * suite pins YOLO_PLAN_SEQUENTIAL because MockHandler queues can't cross a
     * process boundary.
     */
    protected static function planWorkers(int $steps): int
    {
        if (! extension_loaded('pcntl') || ! function_exists('pcntl_fork')) {
            return 1;
        }

        if (! in_array(getenv('YOLO_PLAN_SEQUENTIAL'), [false, '', '0'], true)) {
            return 1;
        }

        return min(8, $steps);
    }

    /**
     * Each child first releases the AWS clients it inherited (a forked copy of a
     * live client shares the parent's open sockets) and ships back plain values
     * only. Failures are gathered rather than fail-fast so a broken first sync
     * surfaces every crashing step in one pass.
     *
     * @param  Collection<int, array{scope: string, step: Step}>  $plan
     * @param  array<string, mixed>  $options
     * @return Collection<int, array{index: int, scope: string, step: Step, status: StepResult|string, elapsed: int, changes: array<int, Change>, warnings: array<int, string>}>
     */
    protected function executePlanConcurrently(Collection $plan, array $options): Collection
    {
        $entries = $plan->values();
        $multiScope = $entries->pluck('scope')->unique()->count() > 1;

        $progress = $this->option('no-progress')
            ? null
            : progress(
                label: sprintf('Planning %d steps across %d workers...', $entries->count(), static::planWorkers($entries->count())),
                steps: $entries->count()
            );

        $progress?->start();

        $tasks = $entries->map(function (array $entry) use ($options, $multiScope): \Closure {
            $label = static::planEntryLabel($entry, $multiScope);

            return function () use ($entry, $options, $label): array {
                $started = time();
                $step = $entry['step'];

                try {
                    $status = $step($options);

                    return [
                        'label' => $label,
                        'status' => $status,
                        'elapsed' => time() - $started,
                        'changes' => method_exists($step, 'changes') ? $step->changes() : [],
                        'warnings' => method_exists($step, 'recordedWarnings') ? $step->recordedWarnings() : [],
                    ];
                } catch (\Throwable $e) {
                    return [
                        'label' => $label,
                        'error' => sprintf('%s: %s', $e::class, $e->getMessage()),
                        'elapsed' => time() - $started,
                    ];
                }
            };
        });

        $results = Fork::new()
            ->concurrent(static::planWorkers($entries->count()))
            ->before(child: static fn () => static::forgetAwsClients())
            ->after(parent: function (mixed $output) use ($progress): void {
                $progress?->label(sprintf('Planned %s', is_array($output) ? $output['label'] : 'step'))->advance();
            })
            ->run(...$tasks->all());

        $progress?->finish();

        $this->ensurePlanWorkersSucceeded($entries, $results);

        return $entries->map(fn (array $entry, int $i): array => [
            'index' => $i + 1,
            'scope' => $entry['scope'],
            'step' => $entry['step'],
            'status' => $results[$i]['status'],
            'elapsed' => $results[$i]['elapsed'],
            'changes' => $results[$i]['changes'],
            'warnings' => $results[$i]['warnings'],
        ]);
    }

    /**
     * A worker that died without reporting (a crash before the step ran, an OOM
     * kill) comes back as a non-array.
     *
     * @param  Collection<int, array{scope: string, step: Step}>  $entries
     * @param  array<int, mixed>  $results
     */
    protected function ensurePlanWorkersSucceeded(Collection $entries, array $results): void
    {
        $failures = [];

        foreach ($entries->all() as $i => $entry) {
            $result = $results[$i] ?? null;

            if (is_array($result) && ! array_key_exists('error', $result)) {
                continue;
            }

            $label = sprintf('%s · %s', $entry['scope'], static::normaliseStep($entry['step']));

            $failures[] = is_array($result)
                ? sprintf('%s — %s', $label, $result['error'])
                : sprintf('%s — worker exited without reporting', $label);
        }

        if ($failures === []) {
            return;
        }

        foreach ($failures as $failure) {
            error($failure);
        }

        throw new \RuntimeException(sprintf('Plan failed for %d step(s).', count($failures)));
    }

    /**
     * @param  Collection<int, array{scope: string, step: Step}>  $planned
     */
    protected function resetRecordedState(Collection $planned): void
    {
        $planned->each(function (array $entry): void {
            if (method_exists($entry['step'], 'resetChanges')) {
                $entry['step']->resetChanges();
            }

            if (method_exists($entry['step'], 'resetWarnings')) {
                $entry['step']->resetWarnings();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{status: StepResult|string, elapsed: int, changes: array<int, Change>, warnings: array<int, string>}
     */
    protected function invokeStep(Step $step, ?Progress $progress, string $label, int $now, array $options): array
    {
        $started = time();

        // Apply only — the plan pass reads fine under the tier cap.
        if ($step instanceof RunsOnBaseCredentials && empty($options['dry-run'])) {
            $this->ensureBaseCredentials();
        }

        // A LongRunning step blocks inside an AWS waiter, so without a heartbeat on
        // every waiter poll its progress frame would freeze and read as hung.
        if ($step instanceof LongRunning && $progress instanceof Progress) {
            $progress->label($label)->hint($step->patienceMessage())->render();

            // Prefer a live line from a shell-out step (RunsProcess) over the static patience message.
            WaitReporter::using(fn () => $progress->label($label)
                ->hint(sprintf(
                    '%s · %s elapsed',
                    Str::limit(WaitReporter::message() ?? $step->patienceMessage(), 100),
                    Helpers::humaniseElapsed(time() - $started)
                ))
                ->render());
        } else {
            $progress?->label($label)
                ->hint(sprintf('%d seconds elapsed', time() - $now))
                ->render();
        }

        try {
            $status = $step->__invoke($options);
        } finally {
            WaitReporter::clear();
        }

        $progress?->advance();

        return [
            'status' => $status,
            'elapsed' => time() - $started,
            'changes' => method_exists($step, 'changes') ? $step->changes() : [],
            'warnings' => method_exists($step, 'recordedWarnings') ? $step->recordedWarnings() : [],
        ];
    }

    /**
     * Attribute diffs and the skip ledger were already shown pre-confirm; neither
     * is repeated here.
     *
     * @param  Collection<int, array{index: int, scope: string, step: Step, status: StepResult|string, elapsed: int}>  $ran
     */
    protected function renderResults(string $environment, Collection $ran, int $elapsed): void
    {
        $multiScope = $ran->pluck('scope')->unique()->count() > 1;

        $lastScope = null;

        $rows = $ran->map(function (array $result) use (&$lastScope, $multiScope): array {
            $row = [(string) $result['index']];

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

        info(sprintf('%s %s in %ds.', $this->completionVerb(), $environment, $elapsed));
    }

    /**
     * Steps buffer warnings (RecordsWarnings) rather than printing inline: a
     * warning written mid-run lands inside the live progress bar's repaint region
     * and scrolls off-screen before it can be read.
     *
     * @param  Collection<int, array{warnings?: array<int, string>}>  $ran
     */
    protected function renderDeferredWarnings(Collection $ran): void
    {
        $warnings = $ran->flatMap(fn (array $entry): array => $entry['warnings'] ?? [])->all();

        if ($warnings === []) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('  <options=bold>Warnings</>');

        foreach ($warnings as $warning) {
            $this->output->writeln(sprintf('  <fg=yellow>• %s</>', $warning));
        }
    }

    /**
     * Creation records no attribute-level Change (no "before" to diff), so without
     * this list a new resource would fold silently into the scope tally.
     *
     * @param  Collection<int, array{scope: string, step: Step, status: StepResult|string, changes: array<int, Change>}>  $plan
     */
    protected function renderWillCreate(Collection $plan, bool $multiScope): void
    {
        $creating = $plan->filter(fn (array $entry): bool => $entry['status'] === StepResult::WOULD_CREATE);

        if ($creating->isEmpty()) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('  <options=bold>Will create</>');

        $creating->each(function (array $entry) use ($multiScope): void {
            $this->output->writeln(sprintf('  <fg=green>+</> %s', static::planEntryLabel($entry, $multiScope)));
        });
    }

    /**
     * @param  Collection<int, array{scope: string, step: Step, changes: array<int, Change>}>  $plan
     */
    protected function renderPendingChanges(Collection $plan, bool $multiScope): void
    {
        $withChanges = $plan->filter(fn (array $entry): bool => $entry['changes'] !== []);

        if ($withChanges->isEmpty()) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('  <options=bold>Pending changes</>');

        $withChanges->each(function (array $entry) use ($multiScope): void {
            $this->output->writeln(sprintf('  <fg=cyan>%s</>', static::planEntryLabel($entry, $multiScope)));

            foreach ($entry['changes'] as $change) {
                $this->output->writeln(sprintf(
                    '    %s: <fg=red>%s</> <fg=gray>→</> <fg=green>%s</>',
                    $change->attribute,
                    $change->from ?? 'absent',
                    $change->to ?? 'absent',
                ));
            }
        });
    }

    /**
     * @param  array{scope: string, step: Step}  $entry
     */
    protected static function planEntryLabel(array $entry, bool $multiScope): string
    {
        return $multiScope
            ? sprintf('%s · %s', $entry['scope'], static::normaliseStep($entry['step']))
            : static::normaliseStep($entry['step']);
    }

    /**
     * @param  Collection<int, array{scope: string, step: Step, reason: string}>  $skipped
     */
    protected function renderSkipping(Collection $skipped): void
    {
        if ($skipped->isEmpty()) {
            return;
        }

        $verbose = $this->output->isVerbose();

        $this->output->writeln('');
        $this->output->writeln('  <options=bold>Skipping</>');

        $skipped
            ->groupBy(fn (array $entry): string => $entry['scope'] . '|' . $entry['reason'])
            ->each(function (Collection $group) use ($verbose): void {
                $first = $group->first();

                $this->output->writeln(sprintf(
                    '  <fg=yellow>•</> %s <fg=gray>(%d)</> — %s',
                    $first['scope'],
                    $group->count(),
                    $first['reason'],
                ));

                if ($verbose) {
                    foreach ($group as $entry) {
                        $this->output->writeln(sprintf(
                            '      <fg=gray>· %s</>',
                            static::normaliseStep($entry['step']),
                        ));
                    }
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

        $options = $this->input->getOptions();

        $output = $steps->map(function (Step $step, int $i) use ($progress, $now, $options): array {
            ['status' => $status, 'elapsed' => $elapsed] = $this->invokeStep($step, $progress, static::normaliseStep($step), $now, $options);

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
            $output->map(fn ($step): array => [
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
     * @return array<int, Step>
     */
    protected function expandStep(Step $step, string $environment): array
    {
        if ($step instanceof HasSubSteps) {
            $subSteps = array_map(
                fn (string $subStepName): Step => match (true) {
                    $step instanceof RunsOnBuild => new ExecuteBuildCommandStep($environment, $subStepName),
                    default => throw new \LogicException('Sub-steps are only supported for build steps.'),
                },
                $step->subSteps(),
            );

            return [$step, ...$subSteps];
        }

        if ($step instanceof ExecutesTenantStep) {
            return collect($this->tenantsToExpand())
                ->map(function (array $config, string $tenantId) use ($step): ExecutesTenantStep {
                    $clone = clone $step;

                    $clone->setTenantId($tenantId)
                        ->setConfig($config);

                    return $clone;
                })->values()->all();
        }

        return [$step];
    }

    /**
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
            StepResult::DELETED => '<fg=green>DELETED</>',
            StepResult::BUILT => '<fg=green>BUILT</>',

            // yellow
            StepResult::SKIPPED => '<fg=yellow>SKIPPED</>',
            StepResult::WOULD_CREATE => '<fg=yellow>WOULD CREATE</>',
            StepResult::WOULD_SYNC => '<fg=yellow>WOULD SYNC</>',
            StepResult::WOULD_DELETE => '<fg=yellow>WOULD DELETE</>',
            StepResult::WOULD_BUILD => '<fg=yellow>WOULD BUILD</>',

            // red
            StepResult::MANIFEST_INVALID => '<fg=red>MANIFEST INVALID</>',
            StepResult::TIMEOUT => '<fg=red>TIMEOUT</>',
            default => $status,
        };
    }

    protected static function normaliseStep(Step $step, $pad = false, $bold = false, $arrow = false): string
    {
        $tenantPrefix = $step instanceof ExecutesTenantStep ? "[{$step->tenantId()}] " : '';

        $name = match (true) {
            $step instanceof ExecutesCommandStep => Str::of($step->name())
                ->when($arrow, fn (Stringable $string) => $string->prepend($arrow ? ' ➡ ' : '')),
            default => Str::of($step::class)
                ->classBasename()
                ->replaceLast('Step', '')
                ->headline()
                ->lower()
                ->ucfirst()
                // Env-backed services tear down via their Sync steps' Teardown branch, so
                // a destroy run would otherwise read "Sync …" while deleting.
                ->when(Destroying::active(), fn (Stringable $string) => $string->replaceFirst('Sync ', 'Teardown '))
                ->when($step instanceof ExecutesTenantStep, fn (Stringable $string) => $string->prepend($tenantPrefix))
                ->when($bold && ! $step instanceof ExecutesTenantStep, fn (Stringable $string) => $string->wrap(before: '<options=bold>', after: '</>'))
        };

        return $name->limit(70)
            ->when($pad, fn (Stringable $string) => $string->padRight(70));
    }
}
