<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Undeletable;

use function Laravel\Prompts\text;

/**
 * The confirm gate for the destroy commands. Where sync asks a one-line y/N, a
 * destroy is irreversible — so this renders a loud red banner, names the
 * resources YOLO will NEVER delete (the RDS database + the bring-your-own app
 * data bucket), then requires the operator to TYPE the environment name. A y/N
 * is too easy to fat-finger for something with no undo.
 *
 * The plan tally printed above already lists what WILL be deleted, and warnings()
 * lists what's kept and why; this gate adds only the banner, the protected-resource
 * callout and the typed confirmation. `--force` / non-interactive bypasses it (CI),
 * exactly as the sync gate does.
 */
trait ConfirmsDestruction
{
    /**
     * The one-line scope summary, shown in the banner — "Permanently delete … for
     * {env}? This cannot be undone." Each destroy command overrides it (also the
     * inherited sync gate's question, kept tested), so the wording stays in one place.
     */
    abstract protected function confirmQuestion(string $environment): string;

    #[\Override]
    protected function confirmGate(string $environment): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        $this->renderDestructionBanner($environment);

        return text(
            label: 'Type the environment name to permanently destroy it',
            placeholder: $environment,
            hint: 'Anything that is not an exact match cancels — nothing is deleted.',
        ) === $environment;
    }

    protected function renderDestructionBanner(string $environment): void
    {
        $bar = fn (string $text): string => sprintf('<fg=white;bg=red;options=bold> %s </>', str_pad($text, 60));

        $this->output->writeln('');
        $this->output->writeln($bar(sprintf('⚠  DESTROY ENVIRONMENT: %s', $environment)));
        $this->output->writeln($bar('PERMANENT · IRREVERSIBLE · NO UNDO'));
        $this->output->writeln('');
        $this->output->writeln(sprintf('  <fg=red;options=bold>%s</>', $this->confirmQuestion($environment)));
        $this->output->writeln('');

        $this->output->writeln('  <options=bold>PROTECTED — YOLO will NEVER delete these:</>');

        foreach ($this->protectedResources() as $resource) {
            $this->output->writeln(sprintf('    <fg=green;options=bold>✓</> %s', $resource));
        }

        $this->output->writeln('');
    }

    /**
     * The resources YOLO will never delete, named for the confirmation: any RDS
     * database in the environment and the bring-your-own app data bucket (when one
     * is configured). Both are forbidden from teardown at the type and runtime
     * level (see {@see Undeletable}); this is the line
     * that tells the operator so before they confirm.
     *
     * @return array<int, string>
     */
    protected function protectedResources(): array
    {
        $protected = [];

        $databases = $this->protectedDatabases();

        foreach ($databases as $database) {
            $protected[] = sprintf('RDS database \'%s\' — your data is safe', $database);
        }

        if ($databases === []) {
            $protected[] = 'Any RDS database — YOLO never deletes a database';
        }

        if (Manifest::has('bucket')) {
            $protected[] = sprintf('App data bucket \'%s\' — your data is safe', Paths::s3AppBucket());
        }

        return $protected;
    }

    /**
     * Named RDS databases to call out as protected. The network-aware destroy
     * commands override this to name the live instances in the environment's VPC;
     * elsewhere there's nothing to enumerate, so the generic line is shown.
     *
     * @return array<int, string>
     */
    protected function protectedDatabases(): array
    {
        return [];
    }
}
