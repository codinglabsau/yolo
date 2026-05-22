<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

class SyncStorageCommand extends SyncSteppedCommand
{
    protected array $steps = [
        Steps\Storage\SyncS3ArtefactBucketStep::class,
        Steps\Storage\SyncS3BucketStep::class,
        Steps\Storage\SyncS3AssetBucketStep::class,
    ];

    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:storage')
            ->setDescription('Sync the storage resources for the given environment');
    }
}
