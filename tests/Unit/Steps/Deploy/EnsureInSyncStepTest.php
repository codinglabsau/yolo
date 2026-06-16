<?php

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Codinglabs\Yolo\Steps\Deploy\EnsureInSyncStep;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * Drive the gate without mocking the whole sync plan by stubbing the verdict the
 * plan would return — the real `check()` (which runs `sync --check` across
 * account → environment → app against live AWS) is proven on a live deploy, not
 * here, like every other AWS-touching path in the suite.
 */
function inSyncStep(int $exitCode, string $planOutput = ''): EnsureInSyncStep
{
    return new class($exitCode, $planOutput) extends EnsureInSyncStep
    {
        public function __construct(private readonly int $exitCode, private readonly string $planOutput) {}

        #[Override]
        protected function check(string $environment, OutputInterface $output): int
        {
            $output->write($this->planOutput);

            return $this->exitCode;
        }
    };
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    Helpers::app()->instance('output', new BufferedOutput());
});

it('passes the gate as SYNCED when the environment is in sync', function (): void {
    expect(inSyncStep(0)([]))->toBe(StepResult::SYNCED);
});

it('refuses the deploy when the environment has drifted, naming the reconcile command', function (): void {
    expect(fn (): StepResult => inSyncStep(1)([]))
        ->toThrow(
            IntegrityCheckException::class,
            'testing is not in sync',
        );
});

it('feeds option names sync actually defines (the live sub-command invocation the seam hides)', function (): void {
    // The unit tests stub check(); this pins that the real `environment` + `--check`
    // + `--no-progress` it would feed SyncCommand bind cleanly against its
    // definition — a renamed sync option would otherwise only surface on a live deploy.
    $command = new SyncCommand();

    $input = new ArrayInput([
        'environment' => 'testing',
        '--check' => true,
        '--no-progress' => true,
    ], $command->getDefinition());

    expect(fn () => $input->bind($command->getDefinition()))->not->toThrow(Exception::class);
    expect($input->getOption('check'))->toBeTrue();
    expect($input->getOption('no-progress'))->toBeTrue();
    expect($input->getArgument('environment'))->toBe('testing');
});

it('surfaces the sync plan diff before refusing so the operator sees what drifted', function (): void {
    $output = new BufferedOutput();
    Helpers::app()->instance('output', $output);

    try {
        inSyncStep(1, 'Pending changes: target group')([]);
    } catch (IntegrityCheckException) {
        // expected — the plan diff is written to the real output before the throw
    }

    expect($output->fetch())->toContain('Pending changes: target group');
});

it('flushes the buffered sub-plan when a plan step crashes, so the failing step is named not swallowed', function (): void {
    $output = new BufferedOutput();
    Helpers::app()->instance('output', $output);

    // The plan runner prints the per-step failure into the (buffered) output, THEN
    // throws the bare summary. Before the fix the buffer was only flushed on a
    // drift *return*, so a crashing plan left the operator with just "Plan failed".
    $step = new class() extends EnsureInSyncStep
    {
        #[Override]
        protected function check(string $environment, OutputInterface $output): int
        {
            $output->write('app · Sync s3 bucket — S3Exception: AccessDenied');

            throw new RuntimeException('Plan failed for 1 step(s).');
        }
    };

    expect(fn (): StepResult => $step([]))->toThrow(RuntimeException::class, 'Plan failed for 1 step(s).');
    expect($output->fetch())->toContain('app · Sync s3 bucket — S3Exception: AccessDenied');
});
