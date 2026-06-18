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
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

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
 * A step that's pending on the plan but, on apply, records a deferred warning
 * and skips — mirroring SyncTypesenseKeyStep, whose live mint can't run until
 * the cluster is up. The warning is buffered, never printed inline.
 */
class RunScopesWarnStep implements Step
{
    use RecordsChanges;
    use RecordsWarnings;

    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            $this->recordChange(Change::make('typesense key', 'absent', 'minted'));

            return StepResult::WOULD_CREATE;
        }

        $this->recordWarning('Typesense key not minted — the cluster is not provisioned yet.');

        return StepResult::SKIPPED;
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
    return runScopesResult($scopes, $options, $verbosity)[0];
}

/**
 * As {@see runScopesCapture()}, but returns the captured output *and* the
 * command exit code, so the `--check` non-zero-on-drift contract can be asserted.
 *
 * @param  array<string, array<int, class-string>>  $scopes
 * @param  array<string, mixed>  $options
 * @return array{0: string, 1: int}
 */
function runScopesResult(array $scopes, array $options = [], int $verbosity = BufferedOutput::VERBOSITY_NORMAL): array
{
    $command = new SyncCommand();

    $input = new ArrayInput(['environment' => 'testing'] + $options, $command->getDefinition());
    $input->setInteractive(false);

    $output = new BufferedOutput();
    $output->setVerbosity($verbosity);

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

it('plans (scopes + skipping) before applying, ending with the results table', function (): void {
    $output = runScopesCapture([
        'environment' => [RunScopesFakeStep::class, RunScopesFakeStep::class],
        'app' => [
            RunScopesFakeStep::class,
            // the three web ingress steps skip on a headless app (no domain)
            Steps\Sync\App\SyncTargetGroupStep::class,
            Steps\Sync\App\SyncForwardRuleStep::class,
            Steps\Sync\App\SyncRedirectRuleStep::class,
        ],
    ], ['--no-progress' => true]);

    // the plan (Will sync + Skipping) renders, then the apply pass + results
    expect($output)
        ->toContain('Will sync')
        ->toContain('environment')
        ->toContain('app')
        ->toContain('Skipping')
        ->toContain('headless app (no ALB / Route 53 / domain)')
        ->toContain('CREATED')
        ->toContain('Synced testing');

    // and the plan really is rendered BEFORE the apply pass — "Will sync"
    // must appear ahead of the completion line, not after it.
    expect(strpos($output, 'Will sync'))->toBeLessThan(strpos($output, 'Synced testing'));
});

it('auto-proceeds without a prompt when non-interactive', function (): void {
    // No exception / no hang means confirmGate short-circuited on !isInteractive.
    $output = runScopesCapture(
        ['environment' => [RunScopesFakeStep::class]],
        ['--no-progress' => true],
    );

    expect($output)->toContain('Synced testing');
});

it('shows every pending attribute change before the confirm gate, even on a real run', function (): void {
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

it('omits the changes section entirely when nothing drifted', function (): void {
    $output = runScopesCapture(
        ['environment' => [RunScopesFakeStep::class]],
        ['--no-progress' => true],
    );

    expect($output)
        ->not->toContain('Pending changes')
        ->not->toContain('Changes applied');
});

it('names brand-new resources under Will create, before the confirm gate', function (): void {
    $output = runScopesCapture(
        ['app' => [RunScopesFakeStep::class]],
        ['--no-progress' => true],
    );

    // A WOULD_CREATE step records no attribute Change, so without the Will create
    // list it would be folded silently into the scope tally and never named.
    // Creation is not an attribute diff, so it stays out of Pending changes.
    expect($output)
        ->toContain('Will create')
        ->toContain('Run scopes fake')
        ->not->toContain('Pending changes');

    // It's part of the plan — rendered ahead of the apply/completion line.
    expect(strpos($output, 'Will create'))->toBeLessThan(strpos($output, 'Synced testing'));
});

it('separates brand-new resources (Will create) from drift on existing ones (Pending changes)', function (): void {
    $output = runScopesCapture(
        ['app' => [RunScopesFakeStep::class, RunScopesChangeStep::class]],
        ['--no-progress' => true],
    );

    // The create is named under Will create; the attribute drift is itemised
    // under Pending changes — and Will create leads, as the more consequential.
    expect($output)
        ->toContain('Will create')
        ->toContain('Pending changes')
        ->toContain('idle_timeout');

    expect(strpos($output, 'Will create'))->toBeLessThan(strpos($output, 'Pending changes'));
});

it('shows the skipped concept summary at normal verbosity but hides per-resource names', function (): void {
    $output = runScopesCapture([
        'app' => [
            Steps\Sync\App\SyncTargetGroupStep::class,
            Steps\Sync\App\SyncForwardRuleStep::class,
            Steps\Sync\App\SyncRedirectRuleStep::class,
        ],
    ], ['--no-progress' => true]);

    expect($output)
        ->toContain('Skipping')
        ->toContain('headless app (no ALB / Route 53 / domain)')
        ->toContain('(3)')                            // concept-summary count
        ->not->toContain('target group') // per-resource detail hidden
        ->not->toContain('forward rule');
});

it('expands the skipped section to per-resource names under -v', function (): void {
    $output = runScopesCapture([
        'app' => [
            Steps\Sync\App\SyncTargetGroupStep::class,
            Steps\Sync\App\SyncForwardRuleStep::class,
            Steps\Sync\App\SyncRedirectRuleStep::class,
        ],
    ], ['--no-progress' => true], BufferedOutput::VERBOSITY_VERBOSE);

    // concept summary still there, plus the per-resource expansion
    // (normaliseStep lowercases everything past the first char)
    expect($output)
        ->toContain('Skipping')
        ->toContain('headless app (no ALB / Route 53 / domain)')
        ->toContain('target group')
        ->toContain('forward rule')
        ->toContain('redirect rule');
});

it('renders command advisories under a Warnings heading, as part of the plan', function (): void {
    // An autoscaling web task that bundles the scheduler triggers the
    // onOneServer advisory on SyncAppCommand, composed up into `sync`.
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['max' => 4]]],
    ]);

    $output = runScopesCapture(
        ['app' => [RunScopesFakeStep::class]],
        ['--no-progress' => true],
    );

    expect($output)
        ->toContain('Warnings')
        ->toContain('onOneServer()');

    // Warnings belong to the plan — rendered ahead of the apply/completion line.
    expect(strpos($output, 'Warnings'))->toBeLessThan(strpos($output, 'Synced testing'));
});

it('omits the Warnings section when the command carries no advisories', function (): void {
    $output = runScopesCapture(
        ['app' => [RunScopesFakeStep::class]],
        ['--no-progress' => true],
    );

    expect($output)->not->toContain('Warnings');
});

it('defers a step warning to a Warnings block after the apply results', function (): void {
    $output = runScopesCapture(
        ['app' => [RunScopesWarnStep::class]],
        ['--no-progress' => true],
    );

    // The warning the step recorded on apply is replayed once, under a heading...
    expect($output)
        ->toContain('Warnings')
        ->toContain('not provisioned yet');

    // ...*after* the apply results — never mid-run, where the live progress bar
    // would clobber it (the bug this buffering fixes).
    expect(strpos($output, 'Synced testing'))->toBeLessThan(strpos($output, 'not provisioned yet'));
});

it('--check exits non-zero on drift and never applies', function (): void {
    [$output, $exitCode] = runScopesResult(
        ['environment' => [RunScopesChangeStep::class]],
        ['--no-progress' => true, '--check' => true],
    );

    expect($exitCode)->toBe(SymfonyCommand::FAILURE);
    expect($output)
        ->toContain('Pending changes')
        ->toContain('idle_timeout')
        ->toContain('Drift detected')
        ->not->toContain('Synced testing'); // gate only — no apply ran
});

it('--check exits zero when the environment is already in sync', function (): void {
    [$output, $exitCode] = runScopesResult(
        ['environment' => [RunScopesCleanStep::class, RunScopesCleanStep::class]],
        ['--no-progress' => true, '--check' => true],
    );

    expect($exitCode)->toBe(SymfonyCommand::SUCCESS);
    expect($output)
        ->toContain('In sync')
        ->not->toContain('Pending changes')
        ->not->toContain('Synced testing');
});

it('skips the apply pass when nothing drifted — no confirm, no results table', function (): void {
    $output = runScopesCapture(
        ['environment' => [RunScopesCleanStep::class, RunScopesCleanStep::class]],
        ['--no-progress' => true],
    );

    expect($output)
        ->toContain('Already in sync')
        ->not->toContain('Pending changes')
        ->not->toContain('Synced testing'); // apply never ran
});

it('apply pass runs only the pending steps — clean steps are dropped from the results table', function (): void {
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
