<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Deploy;

use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\DeployCheck;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

use function Laravel\Prompts\info;

/**
 * A deploy only rolls a new task-definition revision onto the *existing*
 * infrastructure, so deploying onto drift silently ships onto the wrong shape.
 * The gate is whole-stack (account → environment → app) because a deploy is,
 * for most setups, the only moment drift is ever checked, and it runs before
 * the image build so drift fails fast without burning one.
 *
 * Reconciling is admin-tier (it writes IAM/ALB/CloudFront the deployer cap can't
 * touch), so a default/CI deploy can only refuse; `--admin` reconciles inline
 * through the real `yolo sync` and re-checks once — continuing in the same
 * process rather than re-invoking deploy, which could loop if sync never
 * converges. The plan reads run under the deploying identity: in CI that's the
 * deployer role plus AppObserverPolicy, so a read it lacks surfaces as a
 * one-step plan failure, not silent drift.
 */
class EnsureInSyncStep implements Step
{
    public function __construct(protected bool $admin = false) {}

    public function __invoke(array $options): StepResult
    {
        $environment = Helpers::environment();
        $output = Helpers::app('output');

        // Interactive runs render the plan live (see check()); the buffer is what
        // a refusal or crash flushes — empty and harmless when already rendered.
        $buffer = new BufferedOutput($output->getVerbosity(), $output->isDecorated());

        if ($this->planIsClean($environment, $buffer, $output)) {
            info(sprintf('%s is in sync.', $environment));

            return StepResult::SYNCED;
        }

        if (! $this->admin) {
            $output->write($buffer->fetch());

            throw $this->refusal($environment);
        }

        $this->reconcile($environment, $output);

        if (! $this->planIsClean($environment, $buffer, $output)) {
            $output->write($buffer->fetch());

            throw new IntegrityCheckException(sprintf(
                "Refusing to deploy — %s is still not in sync after `yolo sync` (see the plan above).\n"
                . 'Resolve the remaining drift, then redeploy.',
                $environment,
            ));
        }

        info(sprintf('%s reconciled and in sync — continuing deploy.', $environment));

        return StepResult::SYNCED;
    }

    /**
     * A plan that *crashes* isn't a drift verdict: flush the buffered per-step
     * detail before the bare "Plan failed for N step(s)" bubbles up, or the
     * operator can't tell which step failed or why.
     *
     * @phpstan-impure called pre- and post-reconcile and the verdict changes between calls.
     */
    protected function planIsClean(string $environment, BufferedOutput $buffer, OutputInterface $console): bool
    {
        try {
            return $this->check($environment, $buffer, $console) === SyncCommand::SUCCESS;
        } catch (\Throwable $e) {
            $console->write($buffer->fetch());

            throw $e;
        }
    }

    /**
     * The verdict is ignored on purpose: the re-check that follows is
     * authoritative whether sync applied, was declined at its own gate, or only
     * partially converged.
     */
    protected function reconcile(string $environment, OutputInterface $console): int
    {
        $command = new SyncCommand();

        $input = new ArrayInput([
            'environment' => $environment,
        ], $command->getDefinition());
        $input->setInteractive(true);

        Prompt::setOutput($console);

        try {
            return $command->run($input, $console);
        } finally {
            Prompt::setOutput(new ConsoleOutput());
        }
    }

    protected function refusal(string $environment): IntegrityCheckException
    {
        return new IntegrityCheckException(sprintf(
            "Refusing to deploy — %s has drifted from its declared state (see the plan above).\n"
            . "Reconciling drift needs admin permissions, which `yolo deploy` doesn't hold.\n"
            . 'Ask someone with admin to run `yolo sync %s` and then redeploy, '
            . 'or rerun as `yolo deploy %s --admin` if you have admin yourself.',
            $environment,
            $environment,
            $environment,
        ));
    }

    /**
     * Isolated so a unit test can stub the verdict rather than mock the whole
     * sync plan. An interactive deploy renders live so the ~10s check isn't dead
     * air; CI renders into the buffer so a clean deploy stays silent. sync
     * renders through Laravel Prompts' own global output, so that is pointed at
     * the same sink and restored afterwards.
     */
    protected function check(string $environment, OutputInterface $buffer, OutputInterface $console): int
    {
        $watching = $console->isDecorated();
        $sink = $watching ? $console : $buffer;

        $command = new SyncCommand();
        $input = $this->checkInput($environment, $watching);

        Prompt::setOutput($sink);

        // Scoped to the --check run only: SkippedByDeployCheck steps are reconcilers
        // the deployer can't read, but an admin reconcile() must run them for real.
        try {
            return DeployCheck::during(fn (): int => $command->run($input, $sink));
        } finally {
            Prompt::setOutput(new ConsoleOutput());
        }
    }

    /**
     * `--no-progress` only when nobody's watching, so CI doesn't spray cursor
     * codes into the log.
     */
    protected function checkInput(string $environment, bool $watching): ArrayInput
    {
        $arguments = [
            'environment' => $environment,
            '--check' => true,
        ];

        if (! $watching) {
            $arguments['--no-progress'] = true;
        }

        $input = new ArrayInput($arguments, (new SyncCommand())->getDefinition());
        $input->setInteractive(false);

        return $input;
    }
}
