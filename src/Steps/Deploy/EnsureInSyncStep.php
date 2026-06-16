<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Deploy;

use Laravel\Prompts\Prompt;
use Codinglabs\Yolo\Helpers;
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
 * Deploy preflight: refuse to deploy into an environment that has drifted from its
 * declared state. A deploy only rolls a new task-definition revision onto the
 * *existing* infrastructure — it never reconciles it — so shipping onto a stale
 * target group, a changed task role, an un-provisioned listener, or a shared
 * foundation (VPC/ALB/OIDC) that no longer matches the manifest silently deploys
 * onto the wrong shape. This gate runs the full `sync --check` plan
 * (account → environment → app) against live AWS first and aborts on drift.
 *
 * Whole-stack rather than app-only is deliberate: a deploy is the natural — and
 * for most setups the only — moment drift is checked, so the gate covers the
 * shared foundation the app sits on, not just the app's own slice. It also fires
 * sync's claim gate, so an app that claims an env service the environment doesn't
 * offer (e.g. typesense) is refused with a precise message, not a vague drift.
 *
 * It runs *before* the image build (DeployCommand invokes it first in handle()),
 * so a drifted environment fails fast without burning a build. It reuses `sync`
 * verbatim — no sync-command changes — and `--check` plans only, never writes.
 *
 * The plan reads run under whatever identity is deploying. In CI that's the GitHub
 * Actions deployer role, which carries the per-app read surface (AppObserverPolicy,
 * attached by AttachDeployerRolePoliciesStep) on top of its deploy grants, so the
 * whole-stack plan can inspect every service without an AccessDenied aborting the
 * gate — the unscopeable env describes plus this app's log group (content fenced,
 * tags readable on the bare ARN). A read the deploy identity lacks surfaces here as
 * a one-step plan failure, not silent drift.
 */
class EnsureInSyncStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $environment = Helpers::environment();
        $output = Helpers::app('output');

        // When the operator is watching an interactive terminal, the sub-plan
        // renders live (progress bar and all) so the ~10s whole-stack check isn't
        // dead air; in CI it's buffered so an in-sync deploy stays silent. Either
        // way the buffer is what a refusal or crash flushes — empty and harmless
        // when the plan already rendered live.
        $buffer = new BufferedOutput($output->getVerbosity(), $output->isDecorated());

        try {
            $result = $this->check($environment, $buffer, $output);
        } catch (\Throwable $e) {
            // The plan threw instead of returning a verdict — a plan step crashed
            // (e.g. an AWS read the deploy identity can't make). When buffered, the
            // per-step detail the plan runner printed lives in the buffer; flush it
            // before the bare "Plan failed for N step(s)" bubbles up, or the
            // operator is left with no idea which step failed or why.
            $output->write($buffer->fetch());

            throw $e;
        }

        if ($result === SyncCommand::SUCCESS) {
            info(sprintf('%s is in sync.', $environment));

            return StepResult::SYNCED;
        }

        $output->write($buffer->fetch());

        throw new IntegrityCheckException(sprintf(
            "Refusing to deploy — %s is not in sync (see the plan above).\n"
            . 'Reconcile with `yolo sync %s`, then redeploy.',
            $environment,
            $environment,
        ));
    }

    /**
     * Run the full sync plan (account → environment → app) in check mode and return
     * its exit code — plan-only, never writes; non-zero means the environment has
     * drifted (or a claimed service isn't offered). Isolated so a unit test can stub
     * the verdict rather than mock the entire sync plan.
     *
     * Where it renders turns on whether anyone's watching ($console->isDecorated()):
     * an interactive deploy gets the live `yolo sync --check` surface — its progress
     * bar and compact plan straight to the console — so the gate shows activity; a
     * non-interactive run (CI, piped) renders into the buffer with --no-progress so a
     * clean deploy stays silent and only a drift/crash flushes it. sync renders
     * through Laravel Prompts (its own global output, not the command's), so point
     * that at the same sink, then restore a fresh default afterwards — the state YOLO
     * otherwise leaves it in.
     */
    protected function check(string $environment, OutputInterface $buffer, OutputInterface $console): int
    {
        $watching = $console->isDecorated();
        $sink = $watching ? $console : $buffer;

        $command = new SyncCommand();
        $input = $this->checkInput($environment, $watching);

        Prompt::setOutput($sink);

        try {
            return $command->run($input, $sink);
        } finally {
            Prompt::setOutput(new ConsoleOutput());
        }
    }

    /**
     * Build the sub-command input: always `--check` (plan-only) and non-interactive;
     * `--no-progress` only when nobody's watching, so the live deploy keeps the
     * progress bar and CI doesn't spray cursor codes into the log.
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
