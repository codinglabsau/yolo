<?php

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Commands\DestroyCommand;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Commands\DestroyAppCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Commands\SyncSteppedCommand;
use Codinglabs\Yolo\Contracts\PlansSequentially;
use Symfony\Component\Console\Output\BufferedOutput;
use Codinglabs\Yolo\Commands\DestroyEnvironmentCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/** Records the pid of the process that planned it — a forked worker reports a child pid. */
class ParallelPlanPidStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $this->recordChange(Change::make('pid', null, (string) getmypid()));

        return Arr::get($options, 'dry-run') ? StepResult::WOULD_SYNC : StepResult::SYNCED;
    }
}

class ParallelPlanThrowingStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        throw new RuntimeException('exploded in the worker');
    }
}

/** A teardown-style command: marked PlansSequentially, so its plan never forks. */
class SequentialPlanFakeCommand extends SyncCommand implements PlansSequentially {}

/**
 * Drive runScopes() as a plan-only (--check) pass and return [output, exitCode].
 * Accepts the BufferedOutput so callers asserting on an aborted plan can still
 * read what was rendered before the abort.
 *
 * @param  array<string, array<int, class-string>>  $scopes
 * @return array{0: string, 1: int}
 */
function runParallelPlanCheck(array $scopes, ?BufferedOutput $output = null, ?SyncSteppedCommand $command = null): array
{
    $command ??= new SyncCommand();

    $input = new ArrayInput(
        ['environment' => 'testing', '--no-progress' => true, '--check' => true],
        $command->getDefinition(),
    );
    $input->setInteractive(false);

    $output ??= new BufferedOutput();

    $command->input = $input;
    $command->output = $output;

    Prompt::setOutput($output);

    $exitCode = (new ReflectionMethod($command, 'runScopes'))->invoke($command, 'testing', $scopes);

    return [$output->fetch(), $exitCode];
}

beforeEach(function (): void {
    Helpers::app()->instance('runningInAws', false);
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);

    // SyncEnvironmentCommand::warnings() probes the env manifest + registry for
    // idle services during plan render. These tests carry no env-backed service,
    // so a greenfield (no config bucket) world makes that probe a clean no-op.
    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);
});

// The suite pins YOLO_PLAN_SEQUENTIAL=1 (phpunit.xml) because AWS mocks can't
// cross a process boundary; tests below clear the pin to exercise the forked
// path, so always restore it for whatever runs next.
afterEach(function (): void {
    putenv('YOLO_PLAN_SEQUENTIAL=1');
});

it('fans the plan pass out across forked worker processes', function (): void {
    putenv('YOLO_PLAN_SEQUENTIAL');

    [$output, $exitCode] = runParallelPlanCheck([
        'environment' => [ParallelPlanPidStep::class, ParallelPlanPidStep::class],
        'app' => [ParallelPlanPidStep::class, ParallelPlanPidStep::class],
    ]);

    // the plan renders exactly as a sequential pass would, and --check still gates
    expect($exitCode)->toBe(SymfonyCommand::FAILURE);
    expect($output)
        ->toContain('Will sync')
        ->toContain('Pending changes')
        ->toContain('Drift detected');

    // every step planned in a forked child, never in this process
    preg_match_all('/pid: absent → (\d+)/u', $output, $matches);

    expect($matches[1])->toHaveCount(4);

    foreach ($matches[1] as $pid) {
        expect($pid)->not->toBe((string) getmypid());
    }
})->skip(! extension_loaded('pcntl'), 'pcntl is required to fork plan workers');

it('plans in-process when YOLO_PLAN_SEQUENTIAL pins the sequential path', function (): void {
    putenv('YOLO_PLAN_SEQUENTIAL=1');

    [$output] = runParallelPlanCheck([
        'environment' => [ParallelPlanPidStep::class, ParallelPlanPidStep::class],
    ]);

    preg_match_all('/pid: absent → (\d+)/u', $output, $matches);

    expect($matches[1])->toHaveCount(2)
        ->and(array_unique($matches[1]))->toBe([(string) getmypid()]);
});

it('gathers every worker failure instead of dying on the first', function (): void {
    putenv('YOLO_PLAN_SEQUENTIAL');

    $output = new BufferedOutput();
    $caught = null;

    try {
        runParallelPlanCheck([
            'environment' => [ParallelPlanThrowingStep::class, ParallelPlanPidStep::class, ParallelPlanThrowingStep::class],
        ], $output);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toBe('Plan failed for 2 step(s).');

    // both failures were rendered against their step labels before the abort
    expect(substr_count($output->fetch(), 'exploded in the worker'))->toBe(2);
})->skip(! extension_loaded('pcntl'), 'pcntl is required to fork plan workers');

it('caps plan workers at 8 and honours the sequential pin', function (): void {
    $workers = fn (int $steps): int => (new ReflectionMethod(SyncCommand::class, 'planWorkers'))->invoke(null, $steps);

    putenv('YOLO_PLAN_SEQUENTIAL');
    expect($workers(60))->toBe(8)
        ->and($workers(3))->toBe(3)
        ->and($workers(1))->toBe(1);

    putenv('YOLO_PLAN_SEQUENTIAL=1');
    expect($workers(60))->toBe(1);
})->skip(! extension_loaded('pcntl'), 'pcntl is required to fork plan workers');

it('a PlansSequentially command plans in-process even with the fork pin cleared', function (): void {
    // Clear the pin so the forked path is otherwise eligible — a plain SyncCommand
    // would fan these three steps out. The PlansSequentially marker keeps them here.
    putenv('YOLO_PLAN_SEQUENTIAL');

    [$output] = runParallelPlanCheck(
        ['environment' => [ParallelPlanPidStep::class, ParallelPlanPidStep::class, ParallelPlanPidStep::class]],
        null,
        new SequentialPlanFakeCommand(),
    );

    preg_match_all('/pid: absent → (\d+)/u', $output, $matches);

    expect($matches[1])->toHaveCount(3)
        ->and(array_unique($matches[1]))->toBe([(string) getmypid()]);
})->skip(! extension_loaded('pcntl'), 'pcntl is required to fork plan workers');

it('marks every teardown command PlansSequentially, but not sync', function (): void {
    expect(class_implements(DestroyAppCommand::class))->toContain(PlansSequentially::class)
        ->and(class_implements(DestroyCommand::class))->toContain(PlansSequentially::class)
        ->and(class_implements(DestroyEnvironmentCommand::class))->toContain(PlansSequentially::class)
        ->and(class_implements(SyncCommand::class))->not->toContain(PlansSequentially::class);
});
