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
        public function __construct(private readonly int $exitCode, private readonly string $planOutput)
        {
            parent::__construct(admin: false);
        }

        #[Override]
        protected function check(string $environment, OutputInterface $buffer, OutputInterface $console): int
        {
            $buffer->write($this->planOutput);

            return $this->exitCode;
        }
    };
}

/**
 * Stub a *sequence* of check verdicts (the pre-reconcile drift check, then the
 * post-reconcile confirmation) plus a reconcile that records it was invoked — so the
 * reconcile branch is exercised without a real terminal or a live `yolo sync`. The
 * `admin` flag is the gate's only fork: only an admin-tier deploy reconciles inline;
 * a deployer-tier deploy refuses on drift. A decorated output stands in for a real
 * interactive deploy.
 *
 * @param  array<int, int>  $checkVerdicts
 */
function gate(array $checkVerdicts, bool $admin): EnsureInSyncStep
{
    Helpers::app()->instance('output', new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: true));

    return new class($checkVerdicts, $admin) extends EnsureInSyncStep
    {
        public bool $reconciled = false;

        /** @param  array<int, int>  $checkVerdicts */
        public function __construct(private array $checkVerdicts, bool $admin)
        {
            parent::__construct($admin);
        }

        #[Override]
        protected function check(string $environment, OutputInterface $buffer, OutputInterface $console): int
        {
            return array_shift($this->checkVerdicts) ?? 0;
        }

        #[Override]
        protected function reconcile(string $environment, OutputInterface $console): int
        {
            $this->reconciled = true;

            return 0;
        }
    };
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    Helpers::app()->instance('output', new BufferedOutput());
});

it('passes the gate as SYNCED when the environment is in sync', function (): void {
    expect(inSyncStep(0)([]))->toBe(StepResult::SYNCED);
});

it('refuses a default (deployer) deploy on drift, pointing at sync and deploy --admin', function (): void {
    // The deployer tier can't write the shared-foundation drift away, so the gate
    // refuses and names both ways forward: an admin runs `yolo sync`, or rerun with
    // --admin. The exception carries each so the operator isn't left guessing.
    expect(fn (): StepResult => inSyncStep(1)([]))
        ->toThrow(IntegrityCheckException::class, 'yolo sync testing');

    try {
        inSyncStep(1)([]);
    } catch (IntegrityCheckException $e) {
        expect($e->getMessage())
            ->toContain('admin permissions')
            ->toContain('yolo deploy testing --admin');
    }
});

it('does not reconcile a drifted deployer deploy — it refuses, even on an interactive terminal', function (): void {
    // The core of the revert: a decorated (interactive) deploy under the deployer
    // tier no longer offers to reconcile — it refuses outright, like CI. Only
    // --admin reconciles.
    $gate = gate(checkVerdicts: [1], admin: false);

    expect(fn (): StepResult => $gate([]))->toThrow(IntegrityCheckException::class, 'has drifted');
    expect($gate->reconciled)->toBeFalse();
});

it('reconciles inline under --admin then continues when sync converges', function (): void {
    // Admin tier + drift → reconcile → re-check clean → SYNCED.
    $gate = gate(checkVerdicts: [1, 0], admin: true);

    expect($gate([]))->toBe(StepResult::SYNCED);
    expect($gate->reconciled)->toBeTrue();
});

it('refuses when the environment is still drifted after an --admin reconcile, rather than looping', function (): void {
    // Admin tier + drift → reconcile → re-check STILL drifted → abort (sync couldn't
    // converge — don't re-invoke the deploy and loop).
    $gate = gate(checkVerdicts: [1, 1], admin: true);

    expect(fn (): StepResult => $gate([]))->toThrow(IntegrityCheckException::class, 'still not in sync');
    expect($gate->reconciled)->toBeTrue();
});

it('refuses outright in CI — a non-decorated drift never reconciles', function (): void {
    // The default BufferedOutput is non-decorated (CI/piped). A CI deploy runs the
    // deployer tier and can't reconcile, so drift throws outright.
    expect(fn (): StepResult => inSyncStep(1)([]))->toThrow(IntegrityCheckException::class);
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
        protected function check(string $environment, OutputInterface $buffer, OutputInterface $console): int
        {
            $buffer->write('app · Sync s3 bucket — S3Exception: AccessDenied');

            throw new RuntimeException('Plan failed for 1 step(s).');
        }
    };

    expect(fn (): StepResult => $step([]))->toThrow(RuntimeException::class, 'Plan failed for 1 step(s).');
    expect($output->fetch())->toContain('app · Sync s3 bucket — S3Exception: AccessDenied');
});

it('drops --no-progress only when an operator is watching, so CI stays quiet and a live deploy shows the bar', function (): void {
    // checkInput() is the seam that decides whether the gate's sub-plan renders its
    // progress bar. A watched (interactive) deploy keeps it; CI/piped runs pass
    // --no-progress so the plan stays silent and sprays no cursor codes into the log.
    $gate = new class() extends EnsureInSyncStep
    {
        public function input(string $environment, bool $watching): ArrayInput
        {
            return $this->checkInput($environment, $watching);
        }
    };

    $watched = $gate->input('testing', true);
    expect($watched->getOption('check'))->toBeTrue();
    expect($watched->getOption('no-progress'))->toBeFalse();
    expect($watched->isInteractive())->toBeFalse();

    $ci = $gate->input('testing', false);
    expect($ci->getOption('no-progress'))->toBeTrue();
});
