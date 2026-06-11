<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Str;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Laravel\Prompts\Progress;
use Codinglabs\Yolo\WaitReporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\HasSubSteps;
use Codinglabs\Yolo\Contracts\LongRunning;
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
     * Collate, plan, confirm and apply a set of scope-grouped steps as a single flow.
     *
     * The flow is **approve-before-apply**. A plan pass with `dry-run` injected runs
     * each reconciler in compute-only mode and collects its status + attribute-level
     * changes; the runner renders the full "Pending changes" diff and the "Skipping"
     * summary, gates the confirm, *then* runs the apply pass with the original
     * options — but only over the steps the plan flagged as pending (WOULD_CREATE /
     * WOULD_SYNC, or anything that recorded a change). Clean steps — already-synced
     * resources with no drift — are dropped after plan, so apply doesn't repeat
     * their `exists()` / Describe* round-trips or re-tag them, and the post-apply
     * results table only lists what actually changed.
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

        // PLAN PASS — compute-only. dry-run injection means SynchronisesResource
        // and any bespoke reconcilers diff against live state without writing.
        $plan = $this->executePlan($planned, time(), apply: false);

        $this->printPlan($plan, $skipped);

        $pending = $plan->filter(fn (array $entry): bool => static::planEntryHasWork($entry))->values();

        // --check is a CI gate: plan only, never apply, and exit non-zero when
        // the environment has drifted so a pipeline can fail on unsynced infra.
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

        // Apply pass only over the pending entries — the plan-clean steps are
        // already verified in sync and don't need a second Describe* + tag re-put.
        $applyPlan = $pending->map(fn (array $entry): array => [
            'scope' => $entry['scope'],
            'step' => $entry['step'],
        ])->values();

        // Step instances are reused across passes; clear the changes the plan
        // pass recorded so RecordsChanges starts fresh under apply.
        $this->resetRecordedChanges($applyPlan);

        $now = time();

        $applied = $this->executePlan($applyPlan, $now, apply: true);

        $this->renderResults($environment, $applied, time() - $now);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Did the plan pass flag this step as having work for apply to do?
     *
     * A step has pending work when it would create, sync or delete a resource, or
     * when it recorded an attribute-level Change. Everything else — clean SYNCED,
     * SKIPPED, CUSTOM_MANAGED — is dropped before apply.
     *
     * @param  array{status: StepResult|string, changes: array<int, Change>}  $entry
     */
    protected static function planEntryHasWork(array $entry): bool
    {
        return in_array($entry['status'], [StepResult::WOULD_CREATE, StepResult::WOULD_SYNC, StepResult::WOULD_DELETE], true)
            || $entry['changes'] !== [];
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

    /**
     * Render the plan: scope counts, a Will create list of brand-new resources,
     * the per-attribute Pending changes diff for drift on existing resources,
     * and a single Skipping section grouped by scope + reason. With `-v`, the
     * skipped section expands to list every resource under each concept group.
     *
     * @param  Collection<int, array{index: int, scope: string, step: Step, status: StepResult|string, elapsed: int, changes: array<int, Change>}>  $plan
     * @param  Collection<int, array{scope: string, step: Step, reason: string}>  $skipped
     */
    protected function printPlan(Collection $plan, Collection $skipped): void
    {
        $this->output->writeln('  <options=bold>Will sync</>');

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
     * Command-level advisories rendered under the plan's Warnings heading —
     * soft nudges (not gates) the operator should read before confirming.
     * Commands override this; the default plan carries no warnings.
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return [];
    }

    /**
     * Print the Warnings section — rendered last so the advisories sit right
     * above the confirm gate.
     */
    protected function renderWarnings(): void
    {
        $warnings = $this->warnings();

        if ($warnings === []) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('  <options=bold>Warnings</>');

        foreach ($warnings as $warning) {
            $this->output->writeln(sprintf('  <fg=yellow>•</> %s', $warning));
        }
    }

    protected function confirmGate(string $environment): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        return confirm(
            label: sprintf('Apply these changes to %s?', $environment),
        );
    }

    /**
     * Invoke every planned step once. Under `apply: false` (the plan pass)
     * `dry-run` is injected into the options so reconcilers compute their diff
     * without writing; under `apply: true` the original input options flow
     * through unchanged.
     *
     * @param  Collection<int, array{scope: string, step: Step}>  $plan
     * @return Collection<int, array{index: int, scope: string, step: Step, status: StepResult|string, elapsed: int, changes: array<int, Change>}>
     */
    protected function executePlan(Collection $plan, int $now, bool $apply): Collection
    {
        $multiScope = $plan->pluck('scope')->unique()->count() > 1;

        $progress = $this->option('no-progress')
            ? null
            : progress(label: 'Starting first step...', steps: $plan->count());

        $progress?->start();

        $options = $apply
            ? $this->input->getOptions()
            : [...$this->input->getOptions(), 'dry-run' => true];

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
     * Clear changes recorded by a previous pass so the next pass starts clean.
     *
     * @param  Collection<int, array{scope: string, step: Step}>  $planned
     */
    protected function resetRecordedChanges(Collection $planned): void
    {
        $planned->each(function (array $entry): void {
            if (method_exists($entry['step'], 'resetChanges')) {
                $entry['step']->resetChanges();
            }
        });
    }

    /**
     * Render the progress frame for a step, invoke it, and time it.
     *
     * @param  array<string, mixed>  $options
     * @return array{status: StepResult|string, elapsed: int, changes: array<int, Change>}
     */
    protected function invokeStep(Step $step, ?Progress $progress, string $label, int $now, array $options): array
    {
        $started = time();

        // A LongRunning step blocks inside an AWS waiter, so its progress frame
        // would freeze at "0 seconds elapsed" and read as hung. Show the patience
        // message up front and tick an elapsed-time heartbeat on every waiter
        // poll (the one moment control returns to us mid-wait) so the bar keeps
        // moving. Plain steps keep the original elapsed-since-start hint.
        if ($step instanceof LongRunning && $progress instanceof Progress) {
            $progress->label($label)->hint($step->patienceMessage())->render();

            WaitReporter::using(fn () => $progress->label($label)
                ->hint(sprintf('%s · %s elapsed', $step->patienceMessage(), Helpers::humaniseElapsed(time() - $started)))
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
            // Steps that reconcile config (directly, or via SynchronisesResource)
            // record the attributes they changed so the Changes report can surface
            // each current → desired comparison.
            'changes' => method_exists($step, 'changes') ? $step->changes() : [],
        ];
    }

    /**
     * Render the post-apply results: per-step table + completion line. The
     * attribute diffs were already shown pre-confirm in the plan's "Pending
     * changes" section, and the skip ledger was rendered there too — neither
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

        info(sprintf('Synced %s in %ds.', $environment, $elapsed));
    }

    /**
     * Print the Will create section: every resource the plan would stand up
     * fresh (status WOULD_CREATE). Creation records no attribute-level Change —
     * there's no "before" to diff — so without an explicit list a new resource
     * is folded silently into the scope tally and never named. Standing up new
     * (billable, least-reversible) infra is the most consequential thing apply
     * does, so the plan names it before the per-attribute drift diff.
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
     * Print the per-attribute Pending changes section: which attributes each
     * step would reconcile on an *existing* resource, as a current → desired
     * comparison. Brand-new resources are listed by renderWillCreate(), not
     * here. Steps that recorded nothing are omitted, so a clean plan stays
     * quiet and drift stands out.
     *
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
     * The scope-qualified label for a plan entry — "app · Cache cluster" when a
     * multi-scope sync is in play, just "Cache cluster" for a single scope.
     *
     * @param  array{scope: string, step: Step}  $entry
     */
    protected static function planEntryLabel(array $entry, bool $multiScope): string
    {
        return $multiScope
            ? sprintf('%s · %s', $entry['scope'], static::normaliseStep($entry['step']))
            : static::normaliseStep($entry['step']);
    }

    /**
     * Print the Skipping section: one concept summary per scope + reason
     * (always, regardless of verbosity), with the individual resource names
     * listed under each summary when `-v` is set.
     *
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
     * Expand a step into its concrete runnable instances (sub-steps and per-tenant clones).
     *
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
            StepResult::DELETED => '<fg=green>DELETED</>',

            // yellow
            StepResult::SKIPPED => '<fg=yellow>SKIPPED</>',
            StepResult::CUSTOM_MANAGED => '<fg=yellow>CUSTOM MANAGED</>',
            StepResult::WOULD_CREATE => '<fg=yellow>WOULD CREATE</>',
            StepResult::WOULD_SYNC => '<fg=yellow>WOULD SYNC</>',
            StepResult::WOULD_DELETE => '<fg=yellow>WOULD DELETE</>',

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
                ->when($step instanceof ExecutesTenantStep, fn (Stringable $string) => $string->prepend($tenantPrefix))
                ->when($bold && ! $step instanceof ExecutesTenantStep, fn (Stringable $string) => $string->wrap(before: '<options=bold>', after: '</>'))
        };

        return $name->limit(70)
            ->when($pad, fn (Stringable $string) => $string->padRight(70));
    }
}
