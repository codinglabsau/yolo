<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncComputeCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Fargate\SyncEcrRepositoryStep::class,
        Steps\Fargate\SyncEcsClusterStep::class,
        Steps\Fargate\SyncTaskSecurityGroupStep::class,
        Steps\Fargate\SyncLoadBalancerStep::class,
        Steps\Fargate\SyncTargetGroupStep::class,
        Steps\Fargate\SyncHttpListenerStep::class,
        Steps\Fargate\SyncHttpsListenerStep::class,
        Steps\Fargate\SyncListenerRuleStep::class,
        Steps\Fargate\SyncTaskLogGroupStep::class,
        Steps\Fargate\SyncTaskDefinitionStep::class,
        Steps\Fargate\SyncEcsServiceStep::class,
        Steps\CloudFront\SyncAssetDistributionStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:compute')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync the compute resources for the given environment');
    }
}
