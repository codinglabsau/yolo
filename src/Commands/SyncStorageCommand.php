<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncStorageCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Storage\SyncS3ArtefactBucketStep::class,
        Steps\Storage\SyncS3BucketStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:storage')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync the storage resources for the given environment');
    }
}
