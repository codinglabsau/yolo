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
 * Deploy preflight: refuse to deploy into an environment that has drifted from its
 * declared state. A deploy only rolls a new task-definition revision onto the
 * *existing* infrastructure — it never reconciles it — so shipping onto a stale
 * target group, a changed task role, an un-provisioned listener, or a shared
 * foundation (VPC/ALB/OIDC) that no longer matches the manifest silently deploys
 * onto the wrong shape. This gate runs the full `sync --check` plan
 * (account → environment → app) against live AWS first.
 *
 * Reconciling drift is an admin-tier act: it writes the shared foundation (IAM,
 * ALB, CloudFront, autoscaling) the deployer tier deliberately can't touch — so a
 * reconcile run under a deploy's deployer cap would 403 on the first env/account
 * change. Whether the gate can self-heal therefore turns on the tier the deploy is
 * running under:
 *  - **Default deploy (deployer tier — and all CI)** — the deployer can't write the
 *    drift away, so there's nothing to offer: flush the plan and refuse, pointing
 *    the operator at `yolo sync <env>` (run by an admin) or an admin-tier
 *    `yolo deploy <env> --admin`. A drifted pipeline fails loudly; a human reconciles.
 *  - **Admin deploy (`yolo deploy --admin`)** — the operator opted into the admin
 *    tier up front (minted MFA-gated before the build, exactly like `sync`), so the
 *    deploy holds the credentials to reconcile inline. YOLO runs the real
 *    `yolo sync <env>` (the operator approves its plan at sync's own confirm gate),
 *    re-checks once to confirm it converged, and falls through into the build in the
 *    *same* process. The gate is the deploy's first step — nothing has been built or
 *    rolled yet — so a clean re-check means there's nothing already done to redo: we
 *    continue here rather than re-invoking deploy (which would only re-run this gate,
 *    and risk a loop if sync never converges).
 *
 * Whole-stack rather than app-only is deliberate: a deploy is the natural — and
 * for most setups the only — moment drift is checked, so the gate covers the
 * shared foundation the app sits on, not just the app's own slice. It also fires
 * sync's claim gate, so an app that claims an env service the environment doesn't
 * offer (e.g. typesense) is refused with a precise message, not a vague drift.
 *
 * It runs *before* the image build (DeployCommand invokes it first in handle()),
 * so a drifted environment fails fast without burning a build. It reuses `sync`
 * verbatim — no sync-command changes — and the check pass `--check` plans only,
 * never writes; only an admin-tier reconcile (approved at sync's gate) writes anything.
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
    /**
     * @param  bool  $admin  whether the deploy is running under the admin tier
     *                       (`yolo deploy --admin`) — the only tier that can write
     *                       drift away, so the only one allowed to reconcile inline.
     */
    public function __construct(protected bool $admin = false) {}

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

        if ($this->planIsClean($environment, $buffer, $output)) {
            info(sprintf('%s is in sync.', $environment));

            return StepResult::SYNCED;
        }

        // Drifted. Reconciling means admin-tier writes the deployer cap (and every
        // CI deploy) doesn't hold, so a default deploy can't self-heal: flush the
        // plan so the operator/pipeline log sees what drifted, then refuse — the
        // message points at `yolo sync` or an admin-tier `deploy --admin`.
        if (! $this->admin) {
            $output->write($buffer->fetch());

            throw $this->refusal($environment);
        }

        // Admin tier: reconcile through the real `yolo sync` (the operator approves
        // its plan at sync's own confirm gate), then re-check once to confirm it
        // converged.
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
     * Run the `sync --check` plan and report whether the environment is clean.
     *
     * A plan that *crashes* (a step throws — e.g. an AWS read the deploy identity
     * can't make) isn't a drift verdict: flush the per-step detail the plan runner
     * buffered before the bare "Plan failed for N step(s)" bubbles up, or the
     * operator is left with no idea which step failed or why.
     *
     * @phpstan-impure runs the sync plan against live AWS — called twice (pre- and
     * post-reconcile) and the verdict can change between calls, so it must not be
     * memoised.
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
     * Reconcile the environment inline: a full interactive `yolo sync` (no --check),
     * so the operator approves its plan at sync's own confirm gate before anything is
     * written. Renders straight to the console through the same sink the live --check
     * preview uses — same command, same output path, no command-switch flicker.
     *
     * The verdict is ignored on purpose: convergence is decided by the re-check that
     * follows, which is authoritative whether sync applied, was declined at its own
     * gate, or only partially converged.
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

        // Scope the deploy-check flag to this --check run only: the step runner
        // skips SkippedByDeployCheck steps (admin-owned env-backed-service
        // reconcilers the deployer can't read) while it's set. The reconcile()
        // path is deliberately NOT wrapped — an admin reconcile must run those
        // steps for real.
        try {
            return DeployCheck::during(fn (): int => $command->run($input, $sink));
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
