<?php

namespace Codinglabs\Yolo\Commands;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

abstract class SyncSteppedCommand extends SteppedCommand
{
    public function handle(): int
    {
        return $this->runScopes($this->argument('environment'), $this->scopes());
    }

    /**
     * The ordered, scope-labelled steps this command will sync. Labels must stay
     * distinct across the tiers the top-level `sync` composes, or the merge would
     * drop a colliding group's steps.
     *
     * @return array<string, array<int, class-string>>
     */
    abstract public function scopes(): array;

    protected function addSyncOptions(): static
    {
        $this->addArgument('environment', InputArgument::REQUIRED, 'The environment name');
        $this->addOption('check', null, null, 'Plan only and exit non-zero if the environment has drifted (for CI)');
        $this->addOption('no-progress', null, null, 'Hide the progress output');
        $this->addOption('force', 'f', null, 'Skip the confirmation prompt');
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit per-tenant steps to a single tenant id');

        return $this;
    }
}
