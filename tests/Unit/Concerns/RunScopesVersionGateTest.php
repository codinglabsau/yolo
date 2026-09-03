<?php

use Illuminate\Support\Arr;
use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\EnvironmentVersion;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Commands\SyncEnvironmentCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/** Pending on the plan, so the run has something to apply. */
class VersionGatePendingStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        return Arr::get($options, 'dry-run') ? StepResult::WOULD_SYNC : StepResult::SYNCED;
    }
}

/** Already in sync on both passes. */
class VersionGateCleanStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        return StepResult::SYNCED;
    }
}

/**
 * Drive sync:environment's full plan → gate → confirm → apply pipeline from a
 * pinned CLI release and return the terminal output, the exit code and the S3
 * calls made. sync:environment carries no always-on marker read (sync composes
 * the app tier's skew warning, which does), so the capture proves the gate
 * alone decides whether the marker is read.
 *
 * @return array{0: string, 1: int, 2: array<int, array{name: string, args: array<string, mixed>}>}
 */
function runEnvironmentSyncAs(string $cli, array $scopes, array $world, array $options = []): array
{
    $captured = [];
    bindServiceLifecycleWorld($world, $captured);

    $command = new class($cli) extends SyncEnvironmentCommand
    {
        public function __construct(protected string $pinnedVersion)
        {
            parent::__construct();
        }

        protected function cliVersion(): string
        {
            return $this->pinnedVersion;
        }
    };

    $input = new ArrayInput(['environment' => 'testing'] + $options, $command->getDefinition());
    $input->setInteractive(false);

    $output = new BufferedOutput();
    $command->input = $input;
    $command->output = $output;
    Prompt::setOutput($output);

    $exitCode = (new ReflectionMethod($command, 'runScopes'))->invoke($command, 'testing', $scopes);

    return [$output->fetch(), $exitCode, $captured];
}

function markerReads(array $captured): int
{
    return count(array_filter(
        $captured,
        fn (array $call): bool => $call['name'] === 'GetObject' && ($call['args']['Key'] ?? null) === EnvironmentVersion::MARKER_KEY,
    ));
}

beforeEach(function (): void {
    Helpers::app()->instance('runningInAws', false);
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('refuses after the plan when an older CLI would write the environment tier, and never applies', function (): void {
    [$output, $exitCode, $captured] = runEnvironmentSyncAs('v1.2.0', [
        'environment' => [VersionGatePendingStep::class],
    ], ['version' => 'v1.3.0']);

    expect($exitCode)->toBe(SymfonyCommand::FAILURE);
    // The plan renders first — the refusal sits under the changes it would have made.
    expect($output)
        ->toContain('Will sync')
        ->toContain('OLDER')
        ->toContain('v1.3.0')
        ->not->toContain('Synced');
    expect(markerReads($captured))->toBe(1);
});

it('--check refuses the same way, so a drift job on a stale checkout goes red for the right reason', function (): void {
    [$output, $exitCode] = runEnvironmentSyncAs('v1.2.0', [
        'environment' => [VersionGatePendingStep::class],
    ], ['version' => 'v1.3.0'], ['--check' => true]);

    expect($exitCode)->toBe(SymfonyCommand::FAILURE);
    expect($output)->toContain('OLDER')->not->toContain('Drift detected');
});

it('proceeds without reading the marker when the environment tier plans clean', function (): void {
    [$output, $exitCode, $captured] = runEnvironmentSyncAs('v1.2.0', [
        'environment' => [VersionGateCleanStep::class],
    ], ['version' => 'v999.0.0']);

    expect($exitCode)->toBe(SymfonyCommand::SUCCESS);
    expect($output)->toContain('Already in sync')->not->toContain('OLDER');
    expect(markerReads($captured))->toBe(0);
});

it('applies a guarded change from a current CLI', function (): void {
    [$output, $exitCode] = runEnvironmentSyncAs('v1.3.0', [
        'environment' => [VersionGatePendingStep::class],
    ], ['version' => 'v1.3.0']);

    expect($exitCode)->toBe(SymfonyCommand::SUCCESS);
    expect($output)->toContain('Synced')->not->toContain('OLDER');
});

it('stamps a greenfield environment from a tagged release — an unstamped marker never refuses', function (): void {
    [$output, $exitCode] = runEnvironmentSyncAs('v1.3.0', [
        'environment' => [VersionGatePendingStep::class],
    ], ['bucket' => false]);

    expect($exitCode)->toBe(SymfonyCommand::SUCCESS);
    expect($output)->not->toContain('OLDER');
});
