<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

class SyncComputeCommand extends SyncSteppedCommand
{
    protected array $steps = [
        Steps\Fargate\SyncEcrRepositoryStep::class,
        Steps\Fargate\SyncEcsClusterStep::class,
        Steps\Fargate\SyncTaskSecurityGroupStep::class,
        // RDS SG authorises 3306 from the task SG above, so it must run after it.
        Steps\Network\SyncRdsSecurityGroupStep::class,
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
        $this->addSyncOptions()
            ->setName('sync:compute')
            ->setDescription('Sync the compute resources for the given environment');
    }
}
