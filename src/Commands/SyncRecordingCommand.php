<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncRecordingCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Logging\SyncIvsRecordingCloudWatchLogGroupStep::class,
        Steps\Logging\SyncIvsRecordingBucketStep::class,
        Steps\Logging\SyncIvsRealtimeRecordingBucketStep::class,
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
            ->setName('sync:recording')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync the IVS recording resources for the given environment');
    }
}
