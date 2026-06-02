<?php

use Laravel\Prompts\Progress;
use Codinglabs\Yolo\WaitReporter;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Contracts\LongRunning;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The runner gives a LongRunning step a patience banner and an elapsed-time
 * heartbeat that fires on every waiter poll, so a blocking AWS wait keeps the
 * progress bar moving instead of freezing at "0 seconds elapsed".
 *
 * Render/advance are stubbed to no-ops so the assertions run against the
 * Progress object's public state, not terminal output.
 */
function invokeStepWithProgress(LongRunning $step, Progress $progress): void
{
    $command = new SyncCommand();
    $command->input = new ArrayInput(['environment' => 'testing'], $command->getDefinition());

    (new ReflectionMethod($command, 'invokeStep'))
        ->invoke($command, $step, $progress, 'app · Sync the thing', time(), []);
}

function quietProgress(): Progress
{
    return new class(label: 'start', steps: 1) extends Progress
    {
        public function render(): void {}

        public function advance(int $step = 1): void {}
    };
}

function longRunningStep(): LongRunning
{
    return new class() implements LongRunning
    {
        public function __invoke(array $options): StepResult
        {
            // Simulate a waiter handing control back to the heartbeat mid-wait.
            WaitReporter::poll();

            return StepResult::CREATED;
        }

        public function patienceMessage(): string
        {
            return 'Provisioning the thing — usually 5–15 minutes';
        }
    };
}

afterEach(fn () => WaitReporter::clear());

it('renders the patience message and an elapsed heartbeat on each poll', function () {
    $progress = quietProgress();

    invokeStepWithProgress(longRunningStep(), $progress);

    expect($progress->hint)
        ->toContain('Provisioning the thing — usually 5–15 minutes')
        ->toContain('elapsed');
});

it('clears the reporter after the step so a later poll cannot redraw a finished bar', function () {
    $progress = quietProgress();

    invokeStepWithProgress(longRunningStep(), $progress);

    $progress->hint('SENTINEL');
    WaitReporter::poll();

    expect($progress->hint)->toBe('SENTINEL');
});
