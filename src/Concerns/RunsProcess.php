<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\WaitReporter;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * A bare `Process::mustRun()` blocks with its output buffered, so a slow docker
 * build freezes the progress frame and reads as hung. Polling on the wall clock
 * keeps the elapsed heartbeat moving even while a quiet command says nothing.
 * Failure semantics match `mustRun()`.
 */
trait RunsProcess
{
    protected function runProcess(Process $process): void
    {
        $process->start();

        while ($process->isRunning()) {
            $this->reportIncrementalOutput($process);
            WaitReporter::poll();

            usleep(200_000);
        }

        $this->reportIncrementalOutput($process);
        WaitReporter::poll();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    protected function reportIncrementalOutput(Process $process): void
    {
        // docker/BuildKit writes progress to stderr, npm/composer to stdout.
        foreach ([$process->getIncrementalOutput(), $process->getIncrementalErrorOutput()] as $chunk) {
            $lines = array_filter(array_map(trim(...), explode("\n", $chunk)), fn (string $line): bool => $line !== '');

            if ($lines !== []) {
                WaitReporter::line(end($lines));
            }
        }
    }
}
