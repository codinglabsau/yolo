<?php

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RunScopesFakeStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        // Honour dry-run so the plan pass surfaces this step as pending work; apply
        // would otherwise drop it (status CREATED counts as already-done).
        return Arr::get($options, 'dry-run') ? StepResult::WOULD_CREATE : StepResult::CREATED;
    }
}

/** A step that's already in sync — clean SYNCED on both passes, never any drift. */
class RunScopesCleanStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        return StepResult::SYNCED;
    }
}

/** A step that reconciled (or, on a dry-run, would reconcile) one attribute. */
class RunScopesChangeStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $this->recordChange(Change::make('idle_timeout', 30, 60));

        return Arr::get($options, 'dry-run') ? StepResult::WOULD_SYNC : StepResult::SYNCED;
    }
}

/**
 * Drive the full collate → plan → confirm → apply → results pipeline against a
 * non-interactive command and return everything written to the terminal.
 *
 * Non-interactive auto-approves the confirm gate, so both the plan output (Will
 * sync / Pending changes / Skipping) and the apply output (results table +
 * completion line) appear in the captured stream.
 *
 * @param  array<string, array<int, class-string>>  $scopes
 * @param  array<string, mixed>  $options
 */
function runScopesCapture(array $scopes, array $options = [], int $verbosity = BufferedOutput::VERBOSITY_NORMAL): string
{
    $command = new SyncCommand();

    $input = new ArrayInput(['environment' => 'testing'] + $options, $command->getDefinition());
    $input->setInteractive(false);

    $output = new BufferedOutput();
    $output->setVerbosity($verbosity);

    $command->input = $input;
    $command->output = $output;

    Prompt::setOutput($output);

    (new ReflectionMethod($command, 'runScopes'))->invoke($command, 'testing', $scopes);

    return $output->fetch();
}

beforeEach(function () {
    Helpers::app()->instance('runningInAws', false);
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('plans (scopes + skipping) before applying, ending with the results table', function () {
    $output = runScopesCapture([
        'environment' => [RunScopesFakeStep::class, RunScopesFakeStep::class],
        'app' => [
            RunScopesFakeStep::class,
            // the three IVS steps skip unless ivs is enabled
            Steps\Sync\App\SyncIvsCloudWatchLogGroupStep::class,
            Steps\Sync\App\SyncIvsEventBridgeRuleStep::class,
            Steps\Sync\App\SyncIvsEventBridgeTargetStep::class,
        ],
    ], ['--no-progress' => true]);

    // the plan (Will sync + Skipping) renders, then the apply pass + results
    expect($output)
        ->toContain('Will sync')
        ->toContain('environment')
        ->toContain('app')
        ->toContain('Skipping')
        ->toContain('ivs not enabled in manifest')
        ->toContain('CREATED')
        ->toContain('Synced testing');

    // and the plan really is rendered BEFORE the apply pass — "Will sync"
    // must appear ahead of the completion line, not after it.
    expect(strpos($output, 'Will sync'))->toBeLessThan(strpos($output, 'Synced testing'));
});

it('auto-proceeds without a prompt when non-interactive', function () {
    // No exception / no hang means confirmGate short-circuited on !isInteractive.
    $output = runScopesCapture(
        ['environment' => [RunScopesFakeStep::class]],
        ['--no-progress' => true],
    );

    expect($output)->toContain('Synced testing');
});

it('shows every pending attribute change before the confirm gate, even on a real run', function () {
    $output = runScopesCapture(
        ['environment' => [RunScopesChangeStep::class]],
        ['--no-progress' => true],
    );

    // Pending changes is rendered by the plan pass — i.e. *before* the confirm
    // gate fires — so the operator sees what's about to apply, not after.
    expect($output)
        ->toContain('Pending changes')
        ->toContain('idle_timeout')
        ->toContain('30')
        ->toContain('60');

    // And it precedes the completion line.
    expect(strpos($output, 'Pending changes'))->toBeLessThan(strpos($output, 'Synced testing'));
});

it('omits the changes section entirely when nothing drifted', function () {
    $output = runScopesCapture(
        ['environment' => [RunScopesFakeStep::class]],
        ['--no-progress' => true],
    );

    expect($output)
        ->not->toContain('Pending changes')
        ->not->toContain('Changes applied');
});

it('shows the skipped concept summary at normal verbosity but hides per-resource names', function () {
    $output = runScopesCapture([
        'app' => [
            Steps\Sync\App\SyncIvsCloudWatchLogGroupStep::class,
            Steps\Sync\App\SyncIvsEventBridgeRuleStep::class,
            Steps\Sync\App\SyncIvsEventBridgeTargetStep::class,
        ],
    ], ['--no-progress' => true]);

    expect($output)
        ->toContain('Skipping')
        ->toContain('ivs not enabled in manifest')
        ->toContain('(3)')                            // concept-summary count
        ->not->toContain('ivs cloud watch log group') // per-resource detail hidden
        ->not->toContain('ivs event bridge rule');
});

it('expands the skipped section to per-resource names under -v', function () {
    $output = runScopesCapture([
        'app' => [
            Steps\Sync\App\SyncIvsCloudWatchLogGroupStep::class,
            Steps\Sync\App\SyncIvsEventBridgeRuleStep::class,
            Steps\Sync\App\SyncIvsEventBridgeTargetStep::class,
        ],
    ], ['--no-progress' => true], BufferedOutput::VERBOSITY_VERBOSE);

    // concept summary still there, plus the per-resource expansion
    // (normaliseStep lowercases everything past the first char)
    expect($output)
        ->toContain('Skipping')
        ->toContain('ivs not enabled in manifest')
        ->toContain('ivs cloud watch log group')
        ->toContain('ivs event bridge rule')
        ->toContain('ivs event bridge target');
});

it('dry-run renders the plan and stops — no apply, no results table, no completion line', function () {
    $output = runScopesCapture(
        ['environment' => [RunScopesChangeStep::class]],
        ['--no-progress' => true, '--dry-run' => true],
    );

    expect($output)
        ->toContain('Pending changes')
        ->toContain('idle_timeout')
        ->toContain('Dry run')
        ->not->toContain('Synced testing'); // no apply ran
});

it('skips the apply pass when nothing drifted — no confirm, no results table', function () {
    $output = runScopesCapture(
        ['environment' => [RunScopesCleanStep::class, RunScopesCleanStep::class]],
        ['--no-progress' => true],
    );

    expect($output)
        ->toContain('Already in sync')
        ->not->toContain('Pending changes')
        ->not->toContain('Synced testing'); // apply never ran
});

it('apply pass runs only the pending steps — clean steps are dropped from the results table', function () {
    $output = runScopesCapture(
        ['environment' => [RunScopesCleanStep::class, RunScopesChangeStep::class, RunScopesCleanStep::class]],
        ['--no-progress' => true],
    );

    // The drifted step appears in the apply table (renders as SYNCED post-apply
    // because RunScopesChangeStep returns SYNCED when dry-run is off), and the
    // clean RunScopesCleanSteps never re-ran — only one step is in the table.
    expect($output)
        ->toContain('Pending changes')
        ->toContain('idle_timeout')
        ->toContain('Synced testing')
        ->toContain('Run scopes change');

    // Only one row in the table — the table border ─ rules sit immediately above
    // and below the row, so a single `Run scopes change` between them confirms
    // the clean steps were dropped from apply.
    expect(substr_count($output, 'Run scopes change'))->toBe(2); // pending + apply row
    expect($output)->not->toContain('Run scopes clean');
});
