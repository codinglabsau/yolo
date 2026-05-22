<?php

namespace Codinglabs\Yolo\Concerns;

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
     * Collate, plan, confirm and execute a set of domain-grouped steps as a single flow.
     *
     * @param  array<string, array<int, class-string>>  $domains  ordered label => step class names
     */
    protected function runDomains(string $environment, array $domains): int
    {
        Prompt::interactive($this->input->isInteractive());

        [$plan, $skipped] = $this->collateSteps($domains, $environment);

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
     * Expand and partition every domain's steps into a runnable plan and a skipped ledger.
     *
     * @param  array<string, array<int, class-string>>  $domains
     * @return array{0: Collection<int, array{domain: string, step: Step}>, 1: Collection<int, array{domain: string, step: Step, reason: string}>}
     */
    protected function collateSteps(array $domains, string $environment): array
    {
        $plan = collect();
        $skipped = collect();

        foreach ($domains as $label => $stepNames) {
            foreach ($stepNames as $stepName) {
                foreach ($this->expandStep(new $stepName($environment), $environment) as $step) {
                    $reason = $this->skipReason($step);

                    if ($reason === null) {
                        $plan->push(['domain' => $label, 'step' => $step]);
                    } else {
                        $skipped->push(['domain' => $label, 'step' => $step, 'reason' => $reason]);
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

        $plan->groupBy('domain')->each(function (Collection $entries, string $domain) {
            $this->output->writeln(sprintf('  <fg=green>✔</> %s <fg=gray>(%d)</>', $domain, $entries->count()));
        });

        if ($skipped->isNotEmpty()) {
            $this->output->writeln('');
            $this->output->writeln('  <options=bold>Skipping</>');

            $skipped
                ->groupBy(fn (array $entry) => $entry['domain'] . '|' . $entry['reason'])
                ->each(function (Collection $group) {
                    $first = $group->first();

                    $this->output->writeln(sprintf(
                        '  <fg=yellow>•</> %s <fg=gray>(%d)</> — %s',
                        $first['domain'],
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
     * @param  Collection<int, array{domain: string, step: Step}>  $plan
     * @return Collection<int, array{index: int, domain: string, step: Step, status: StepResult|string, elapsed: int}>
     */
    protected function executePlan(Collection $plan, int $now): Collection
    {
        $multiDomain = $plan->pluck('domain')->unique()->count() > 1;

        $progress = $this->option('no-progress')
            ? null
            : progress(label: 'Starting first step...', steps: $plan->count());

        $progress?->start();

        $ran = $plan->values()->map(function (array $entry, int $i) use ($progress, $now, $multiDomain) {
            $step = $entry['step'];

            $label = $multiDomain
                ? sprintf('%s · %s', $entry['domain'], static::normaliseStep($step))
                : static::normaliseStep($step);

            return [
                'index' => $i + 1,
                'domain' => $entry['domain'],
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
     * @return array{status: StepResult|string, elapsed: int}
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

        return ['status' => $status, 'elapsed' => time() - $started];
    }

    /**
     * @param  Collection<int, array{index: int, domain: string, step: Step, status: StepResult|string, elapsed: int}>  $ran
     * @param  Collection<int, array{domain: string, step: Step, reason: string}>  $skipped
     */
    protected function renderResults(string $environment, Collection $ran, Collection $skipped, int $elapsed): void
    {
        $multiDomain = $ran->pluck('domain')->unique()->count() > 1;

        $lastDomain = null;

        $rows = $ran->map(function (array $result) use (&$lastDomain, $multiDomain) {
            $row = [$result['index']];

            if ($multiDomain) {
                $row[] = $result['domain'] === $lastDomain ? '' : $result['domain'];
                $lastDomain = $result['domain'];
            }

            $row[] = static::normaliseStep($result['step'], pad: true, bold: true, arrow: true);
            $row[] = static::renderStatus($result['status']);
            $row[] = sprintf('%ds', $result['elapsed']);

            return $row;
        });

        if ($rows->isNotEmpty()) {
            table(
                $multiDomain
                    ? ['#', 'Domain', 'Step', 'Status', 'Elapsed']
                    : ['#', 'Step', 'Status', 'Elapsed'],
                $rows->all()
            );
        }

        if ($skipped->isNotEmpty()) {
            if ($this->output->isVerbose()) {
                table(
                    ['Domain', 'Step', 'Reason'],
                    $skipped->map(fn (array $entry) => [
                        $entry['domain'],
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
            return collect(Manifest::tenants())
                ->map(function (array $config, string $tenantId) use ($step) {
                    $clone = clone $step;

                    $clone->setTenantId($tenantId)
                        ->setConfig($config);

                    return $clone;
                })->values()->all();
        }

        return [$step];
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
            StepResult::OUT_OF_SYNC => '<fg=red>OUT OF SYNC</>',
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
