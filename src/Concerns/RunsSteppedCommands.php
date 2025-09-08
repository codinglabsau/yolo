<?php

namespace Codinglabs\Yolo\Concerns;

use Illuminate\Support\Str;
use Codinglabs\Yolo\Manifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Contracts\HasSubSteps;
use Codinglabs\Yolo\Contracts\RunsOnBuild;
use Codinglabs\Yolo\Contracts\RunsOnAwsQueue;
use Codinglabs\Yolo\Contracts\ExecutesTenantStep;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;
use Codinglabs\Yolo\Contracts\ExecutesCommandStep;
use Codinglabs\Yolo\Steps\ExecuteBuildCommandStep;
use Codinglabs\Yolo\Steps\ExecuteCommandOnAwsStep;
use Codinglabs\Yolo\Steps\ExecuteCommandOnAwsQueueStep;
use Codinglabs\Yolo\Steps\ExecuteCommandOnAwsSchedulerStep;

use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\progress;

trait RunsSteppedCommands
{
    use ChecksIfCommandsShouldBeRunning;

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
            $progress?->label(static::normaliseStep($step))
                ->hint(sprintf('%d seconds elapsed', time() - $now))
                ->render();

            $started = time();

            $status = $step->__invoke($this->input->getOptions(), $this);

            $progress?->advance();

            return [
                $i + 1,
                static::normaliseStep($step, pad: true, bold: true, arrow: true),
                match ($status) {
                    // green
                    StepResult::CREATED => '<fg=green>CREATED</>',
                    StepResult::SUCCESS => '<fg=green>SUCCESS</>',
                    StepResult::SYNCED => '<fg=cyan>SYNCED</>',

                    // yellow
                    StepResult::SKIPPED => '<fg=yellow>SKIPPED</>',
                    StepResult::WOULD_SKIP => '<fg=yellow>WOULD SKIP</>',
                    StepResult::CUSTOM_MANAGED => '<fg=yellow>CUSTOM MANAGED</>',
                    StepResult::WOULD_CREATE => '<fg=yellow>WOULD CREATE</>',
                    StepResult::WOULD_SYNC => '<fg=yellow>WOULD SYNC</>',

                    // red
                    StepResult::MANIFEST_INVALID => '<fg=red>MANIFEST INVALID</>',
                    StepResult::OUT_OF_SYNC => '<fg=red>OUT OF SYNC</>',
                    StepResult::TIMEOUT => '<fg=red>TIMEOUT</>',
                    default => is_string($status)
                        ? $status
                        : '',
                },
                time() - $started,
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
            ->flatMap(function (string $stepName) use ($environment) {
                $step = new $stepName($environment);

                if ($step instanceof HasSubSteps) {
                    return collect($step->__invoke())
                        ->map(fn (string $subStepName) => match (true) {
                            $step instanceof RunsOnBuild => new ExecuteBuildCommandStep($environment, $subStepName),
                            $step instanceof RunsOnAwsQueue => new ExecuteCommandOnAwsQueueStep($environment, $subStepName),
                            $step instanceof RunsOnAwsScheduler => new ExecuteCommandOnAwsSchedulerStep($environment, $subStepName),
                            $step instanceof RunsOnAws => new ExecuteCommandOnAwsStep($environment, $subStepName),
                        })
                        ->prepend($step);
                }

                if ($step instanceof ExecutesTenantStep) {
                    return collect(Manifest::tenants())
                        ->map(function (array $config, string $tenantId) use ($step) {
                            $step = clone $step;

                            $step->setTenantId($tenantId)
                                ->setConfig($config);

                            return $step;
                        })->values();
                }

                return [$step];
            })
            ->filter(fn (Step $step) => $this->shouldBeRunning($step));
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

        return $name->limit(50)
            ->when($pad, fn (Stringable $string) => $string->padRight(50));
    }
}
