<?php

namespace Codinglabs\Yolo\Commands;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

abstract class SyncSteppedCommand extends SteppedCommand
{
    public function handle(): int
    {
        return $this->runDomains($this->argument('environment'), $this->domains());
    }

    /**
     * The ordered, domain-labelled steps this command will sync. Labels must stay
     * distinct across the tiers the top-level `sync` composes, or the merge would
     * drop a colliding group's steps.
     *
     * @return array<string, array<int, class-string>>
     */
    abstract public function domains(): array;

    protected function addSyncOptions(): static
    {
        $this->addArgument('environment', InputArgument::REQUIRED, 'The environment name');
        $this->addOption('dry-run', null, null, 'Show what would change without applying it');
        $this->addOption('no-progress', null, null, 'Hide the progress output');
        $this->addOption('force', 'f', null, 'Skip the confirmation prompt');
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit per-tenant steps to a single tenant id');

        return $this;
    }
}
