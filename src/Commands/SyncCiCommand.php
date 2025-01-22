<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncCiCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Ci\SyncCodeDeployApplicationStep::class,
        Steps\Ci\SyncCodeDeployDeploymentConfigStep::class,
        Steps\Ci\SyncCodeDeploySchedulerDeploymentGroupStep::class,
        Steps\Ci\SyncCodeDeployQueueDeploymentGroupStep::class,
        Steps\Ci\SyncCodeDeployWebDeploymentGroupStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:ci')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync continuous integration AWS resources');
    }
}
