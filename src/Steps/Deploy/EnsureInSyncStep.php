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
 * Actions deployer role, which carries AWS-managed ReadOnlyAccess (attached by
 * AttachDeployerRolePoliciesStep) so the whole-stack plan can inspect every service
 * without an AccessDenied aborting the gate.
 */
class EnsureInSyncStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $environment = Helpers::environment();
        $output = Helpers::app('output');

        // Buffer the whole sub-plan so an in-sync deploy stays quiet; only a
        // refusal surfaces the full drift diff sync produced.
        $buffer = new BufferedOutput($output->getVerbosity(), $output->isDecorated());

        if ($this->check($environment, $buffer) === SyncCommand::SUCCESS) {
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
     * sync renders through Laravel Prompts, which writes to its own global output
     * rather than the command's — so point that at the buffer too (and restore it to
     * a fresh default afterwards, the state YOLO otherwise leaves it in) or the
     * plan's headers and drift line would bypass the buffer and leak onto a clean
     * deploy.
     */
    protected function check(string $environment, OutputInterface $output): int
    {
        $command = new SyncCommand();

        $input = new ArrayInput([
            'environment' => $environment,
            '--check' => true,
            '--no-progress' => true,
        ], $command->getDefinition());

        $input->setInteractive(false);

        Prompt::setOutput($output);

        try {
            return $command->run($input, $output);
        } finally {
            Prompt::setOutput(new ConsoleOutput());
        }
    }
}
