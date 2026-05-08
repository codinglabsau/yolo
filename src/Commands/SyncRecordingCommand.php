<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncRecordingCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Recording\SyncIvsRecordingCloudWatchLogGroupStep::class,
        Steps\Recording\SyncIvsRecordingBucketStep::class,
        Steps\Recording\SyncIvsRealtimeRecordingBucketStep::class,
        Steps\Recording\SyncIvsRecordingConfigurationStep::class,
        Steps\Recording\SyncIvsStorageConfigurationStep::class,
        Steps\Recording\SyncIvsRecordingEventBridgeRuleStep::class,
        Steps\Recording\SyncIvsRecordingEventBridgeTargetStep::class,
        Steps\Recording\SyncIvsRealtimeRecordingEventBridgeRuleStep::class,
        Steps\Recording\SyncIvsRealtimeRecordingEventBridgeTargetStep::class,
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
