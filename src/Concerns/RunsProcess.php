<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\WaitReporter;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Runs a child process while keeping the progress bar alive.
 *
 * A bare `Process::mustRun()` blocks with its output buffered, so a slow
 * docker build or `npm ci` freezes the step's frame for ~2 minutes and reads
 * as hung. This drives the process from a poll loop instead: every tick it
 * feeds the child's latest output line to `WaitReporter::line()` and pings
 * `poll()`, so a step marked LongRunning redraws its hint with what the build
 * is actually doing — and the loop ticks on the wall clock, so the elapsed
 * heartbeat keeps moving even while a quiet command (a silent `npm ci`, a
 * cached docker layer) says nothing.
 *
 * Failure semantics match `mustRun()`: a non-zero exit throws
 * ProcessFailedException carrying the captured output.
 */
trait RunsProcess
{
    protected function runProcess(Process $process): void
    {
        $process->start();

        while ($process->isRunning()) {
            $this->reportIncrementalOutput($process);
            WaitReporter::poll();

            // 5×/sec: frequent enough to feel live, idle enough not to spin a core.
            usleep(200_000);
        }

        // Surface the tail emitted between the last poll and exit.
        $this->reportIncrementalOutput($process);
        WaitReporter::poll();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    protected function reportIncrementalOutput(Process $process): void
    {
        // docker/BuildKit writes progress to stderr, npm/composer to stdout —
        // drain both and surface the last non-blank line of whatever just landed.
        foreach ([$process->getIncrementalOutput(), $process->getIncrementalErrorOutput()] as $chunk) {
            $lines = array_filter(array_map(trim(...), explode("\n", $chunk)), fn (string $line): bool => $line !== '');

            if ($lines !== []) {
                WaitReporter::line(end($lines));
            }
        }
    }
}
