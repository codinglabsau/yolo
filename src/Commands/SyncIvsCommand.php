<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncIvsCommand extends SteppedCommand
{
    protected array $steps = [
        // cloudwatch log group for ivs events
        Steps\Ivs\SyncCloudWatchLogGroupStep::class,

        // eventbridge rule to capture ivs state changes
        Steps\Ivs\SyncEventBridgeRuleStep::class,

        // eventbridge target to send events to cloudwatch logs
        Steps\Ivs\SyncEventBridgeTargetStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:ivs')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync IVS resources for the given environment');
    }
}
