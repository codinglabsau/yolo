<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

class SyncNetworkCommand extends SyncSteppedCommand
{
    protected array $steps = [
        // vpc
        Steps\Network\SyncVpcStep::class,

        // internet gateway
        Steps\Network\SyncInternetGatewayStep::class,
        Steps\Network\SyncInternetGatewayAttachmentStep::class,

        // subnets
        Steps\Network\SyncPublicSubnetAStep::class,
        Steps\Network\SyncPublicSubnetBStep::class,
        Steps\Network\SyncPublicSubnetCStep::class,
        Steps\Network\SyncRdsSubnetStep::class,

        // route table
        Steps\Network\SyncRouteTableStep::class,
        Steps\Network\SyncDefaultRouteStep::class,
        Steps\Network\SyncPublicSubnetsAssociationToRouteTableStep::class,

        // security groups
        Steps\Network\SyncLoadBalancerSecurityGroupStep::class,
        Steps\Network\SyncEc2SecurityGroupStep::class,
        Steps\Network\SyncRdsSecurityGroupStep::class,

        // sns
        Steps\Network\SyncSnsAlarmTopicStep::class,
    ];

    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:network')
            ->setDescription('Sync the network resources for the given environment');
    }
}
