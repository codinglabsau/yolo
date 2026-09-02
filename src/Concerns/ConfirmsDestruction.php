<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Undeletable;

use function Laravel\Prompts\text;

/**
 * A destroy has no undo, so a y/N is too easy to fat-finger — the operator must
 * type the environment name. `--force` / non-interactive bypasses it exactly as
 * the sync gate does.
 */
trait ConfirmsDestruction
{
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
     * Both are forbidden from teardown at the type and runtime level ({@see Undeletable});
     * this tells the operator so before they confirm.
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
     * The network-aware destroy commands override this to name the live instances.
     *
     * @return array<int, string>
     */
    protected function protectedDatabases(): array
    {
        return [];
    }
}
