<?php

use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Commands\SyncLoggingCommand;
use Symfony\Component\Console\Output\BufferedOutput;

class RunDomainsFakeStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        return StepResult::CREATED;
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
        'Logging' => (new SyncLoggingCommand())->steps(),
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
