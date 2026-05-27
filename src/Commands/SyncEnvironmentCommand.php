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

    public function scopes(): array
    {
        return [
            'environment' => [
                // network
                Steps\Sync\Environment\SyncVpcStep::class,
                Steps\Sync\Environment\SyncInternetGatewayStep::class,
                Steps\Sync\Environment\SyncInternetGatewayAttachmentStep::class,
                Steps\Sync\Environment\SyncPublicSubnetAStep::class,
                Steps\Sync\Environment\SyncPublicSubnetBStep::class,
                Steps\Sync\Environment\SyncPublicSubnetCStep::class,
                Steps\Sync\Environment\SyncRdsSubnetStep::class,
                Steps\Sync\Environment\SyncRouteTableStep::class,
                Steps\Sync\Environment\SyncDefaultRouteStep::class,
                Steps\Sync\Environment\SyncPublicSubnetsAssociationToRouteTableStep::class,
                Steps\Sync\Environment\SyncLoadBalancerSecurityGroupStep::class,
                Steps\Sync\Environment\SyncSnsAlarmTopicStep::class,
                // shared IAM (task + execution roles)
                Steps\Sync\Environment\SyncEcsTaskPolicyStep::class,
                Steps\Sync\Environment\SyncEcsTaskRoleStep::class,
                Steps\Sync\Environment\AttachEcsTaskRolePoliciesStep::class,
                Steps\Sync\Environment\SyncEcsExecutionRoleStep::class,
                Steps\Sync\Environment\AttachEcsExecutionRolePoliciesStep::class,
                // load balancer + :80 listener
                Steps\Sync\Environment\SyncLoadBalancerStep::class,
                Steps\Sync\Environment\SyncHttpListenerStep::class,
            ],
        ];
    }
}
