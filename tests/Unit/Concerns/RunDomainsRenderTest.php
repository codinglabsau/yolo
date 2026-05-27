<?php

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Codinglabs\Yolo\Commands\SyncAppCommand;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RunDomainsFakeStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        return StepResult::CREATED;
    }
}

/** A step that reconciled (or, on a dry-run, would reconcile) one attribute. */
class RunDomainsChangeStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $this->recordChange(Change::make('idle_timeout', 30, 60));

        return Arr::get($options, 'dry-run') ? StepResult::WOULD_SYNC : StepResult::SYNCED;
    }
}

/**
 * Drive the full collate → determinations → confirm → execute → results pipeline
 * against a non-interactive command and return everything written to the terminal.
 *
 * @param  array<string, array<int, class-string>>  $domains
 * @param  array<string, mixed>  $options
 */
function runDomainsCapture(array $domains, array $options = []): string
{
    $command = new SyncCommand();

    $input = new ArrayInput(['environment' => 'testing'] + $options, $command->getDefinition());
    $input->setInteractive(false);

    $output = new BufferedOutput();

    $command->input = $input;
    $command->output = $output;

    Prompt::setOutput($output);

    (new ReflectionMethod($command, 'runDomains'))->invoke($command, 'testing', $domains);

    return $output->fetch();
}

beforeEach(function () {
    Helpers::app()->instance('runningInAws', false);
    writeManifest(['aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2']]);
});

it('prints determinations, runs the plan, and reports skips in one flow', function () {
    $output = runDomainsCapture([
        'Network' => [RunDomainsFakeStep::class, RunDomainsFakeStep::class],
        'Storage' => [RunDomainsFakeStep::class],
        'Logging' => (new SyncAppCommand())->domains()['Logging'],
    ], ['--no-progress' => true]);

    // up-front determinations summary
    expect($output)
        ->toContain('Will sync')
        ->toContain('Network')
        ->toContain('Storage')
        ->toContain('Skipping')
        ->toContain('aws.ivs not enabled in manifest');

    // executed results, skip summary, and completion line
    expect($output)
        ->toContain('CREATED')
        ->toContain('3 steps skipped')
        ->toContain('Synced testing');
});

it('auto-proceeds without a prompt when non-interactive', function () {
    // No exception / no hang means confirmGate short-circuited on !isInteractive.
    $output = runDomainsCapture(
        ['Network' => [RunDomainsFakeStep::class]],
        ['--no-progress' => true],
    );

    expect($output)->toContain('Synced testing');
});

it('reports each reconciled attribute under an applied-changes section', function () {
    $output = runDomainsCapture(
        ['Network' => [RunDomainsChangeStep::class]],
        ['--no-progress' => true],
    );

    expect($output)
        ->toContain('Changes applied')
        ->toContain('idle_timeout')
        ->toContain('30')
        ->toContain('60');
});

it('frames the attribute changes as pending on a dry-run', function () {
    $output = runDomainsCapture(
        ['Network' => [RunDomainsChangeStep::class]],
        ['--no-progress' => true, '--dry-run' => true],
    );

    expect($output)
        ->toContain('Pending changes')
        ->toContain('idle_timeout');
});

it('omits the changes section entirely when nothing drifted', function () {
    $output = runDomainsCapture(
        ['Network' => [RunDomainsFakeStep::class]],
        ['--no-progress' => true],
    );

    expect($output)
        ->not->toContain('Changes applied')
        ->not->toContain('Pending changes');
});
