<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

class SyncLoggingCommand extends SyncSteppedCommand
{
    protected array $steps = [
        // ivs
        Steps\Logging\SyncIvsCloudWatchLogGroupStep::class,
        Steps\Logging\SyncIvsEventBridgeRuleStep::class,
        Steps\Logging\SyncIvsEventBridgeTargetStep::class,
    ];

    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:logging')
            ->setDescription('Sync the logging resources for the given environment');
    }
}
