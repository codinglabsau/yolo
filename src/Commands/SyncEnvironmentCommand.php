<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

/**
 * Writer of env-shared (environment-tier) resources — one set per environment, shared by
 * every app in it (VPC, subnets, ALB, shared IAM roles). Blast radius: all apps
 * in the environment. Apps depend on these and additively attach, but never
 * mutate them.
 */
class SyncEnvironmentCommand extends SyncSteppedCommand
{
    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:environment')
            ->setDescription('Sync the env-shared (environment-tier) resources for the given environment');
    }

    public function domains(): array
    {
        return [
            'Network' => [
                Steps\Network\SyncVpcStep::class,
                Steps\Network\SyncInternetGatewayStep::class,
                Steps\Network\SyncInternetGatewayAttachmentStep::class,
                Steps\Network\SyncPublicSubnetAStep::class,
                Steps\Network\SyncPublicSubnetBStep::class,
                Steps\Network\SyncPublicSubnetCStep::class,
                Steps\Network\SyncRdsSubnetStep::class,
                Steps\Network\SyncRouteTableStep::class,
                Steps\Network\SyncDefaultRouteStep::class,
                Steps\Network\SyncPublicSubnetsAssociationToRouteTableStep::class,
                Steps\Network\SyncLoadBalancerSecurityGroupStep::class,
                Steps\Network\SyncSnsAlarmTopicStep::class,
            ],
            'IAM (shared)' => [
                Steps\Iam\SyncEcsTaskPolicyStep::class,
                Steps\Iam\SyncEcsTaskRoleStep::class,
                Steps\Iam\AttachEcsTaskRolePoliciesStep::class,
                Steps\Iam\SyncEcsExecutionRoleStep::class,
                Steps\Iam\AttachEcsExecutionRolePoliciesStep::class,
            ],
            'Load balancer' => [
                Steps\Fargate\SyncLoadBalancerStep::class,
                Steps\Fargate\SyncHttpListenerStep::class,
            ],
        ];
    }
}
