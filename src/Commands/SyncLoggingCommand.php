<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncLoggingCommand extends SteppedCommand
{
    protected array $steps = [
        // ivs
        Steps\Logging\SyncIvsCloudWatchLogGroupStep::class,
        Steps\Logging\SyncIvsEventBridgeRuleStep::class,
        Steps\Logging\SyncIvsEventBridgeTargetStep::class,

        // ivs recording
        Steps\Logging\SyncIvsRecordingConfigurationStep::class,
        Steps\Logging\SyncIvsStorageConfigurationStep::class,
        Steps\Logging\SyncIvsRecordingEventBridgeRuleStep::class,
        Steps\Logging\SyncIvsRecordingEventBridgeTargetStep::class,
        Steps\Logging\SyncIvsRealtimeRecordingEventBridgeRuleStep::class,
        Steps\Logging\SyncIvsRealtimeRecordingEventBridgeTargetStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:logging')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync the logging resources for the given environment');
    }
}
