<?php

use Codinglabs\Yolo\WaitReporter;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\RunsProcess;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * RunsProcess runs a child process from a poll loop so the progress bar keeps
 * moving: it feeds the child's latest output line to WaitReporter and throws on
 * a non-zero exit, matching mustRun()'s failure semantics.
 */
function processRunner(): object
{
    return new class()
    {
        use RunsProcess;

        public function run(Process $process): void
        {
            $this->runProcess($process);
        }
    };
}

afterEach(fn () => WaitReporter::clear());

it('reports the last non-blank output line to the WaitReporter', function (): void {
    processRunner()->run(Process::fromShellCommandline('echo first; echo ""; echo last'));

    expect(WaitReporter::message())->toBe('last');
});

it('drains output from a process that exits before the poll loop iterates', function (): void {
    processRunner()->run(Process::fromShellCommandline('printf "only-line\n"'));

    expect(WaitReporter::message())->toBe('only-line');
});

it('throws ProcessFailedException on a non-zero exit, like mustRun', function (): void {
    processRunner()->run(Process::fromShellCommandline('exit 7'));
})->throws(ProcessFailedException::class);
