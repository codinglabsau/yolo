<?php

namespace Codinglabs\Yolo\Commands;

use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;

abstract class SyncSteppedCommand extends SteppedCommand
{
    public function handle(): int
    {
        return $this->runDomains(
            $this->argument('environment'),
            [$this->domainLabel() => $this->steps()],
        );
    }

    protected function domainLabel(): string
    {
        return (string) Str::of($this->getName())
            ->after('sync')
            ->trim(':')
            ->headline();
    }

    protected function addSyncOptions(): static
    {
        $this->addArgument('environment', InputArgument::REQUIRED, 'The environment name');
        $this->addOption('dry-run', null, null, 'Show what would change without applying it');
        $this->addOption('no-progress', null, null, 'Hide the progress output');
        $this->addOption('force', 'f', null, 'Skip the confirmation prompt');

        return $this;
    }
}
