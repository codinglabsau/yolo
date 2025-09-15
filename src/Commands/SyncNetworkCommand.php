<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncNetworkCommand extends SteppedCommand
{
    protected array $steps = [
        // vpc
        Steps\Network\SyncVpcStep::class,

        // internet gateway
        Steps\Network\SyncInternetGatewayStep::class,
        Steps\Network\SyncInternetGatewayAttachmentStep::class,
        //
        //        // subnets
        //        Steps\Network\SyncPublicSubnetAStep::class,
        //        Steps\Network\SyncPublicSubnetBStep::class,
        //        Steps\Network\SyncPublicSubnetCStep::class,
        //        Steps\Network\SyncRdsSubnetStep::class,
        //
        //        // route table
        //        Steps\Network\SyncRouteTableStep::class,
        //        Steps\Network\SyncDefaultRouteStep::class,
        //        Steps\Network\SyncPublicSubnetsAssociationToRouteTableStep::class,
        //
        //        // security groups
        Steps\Network\SyncLoadBalancerSecurityGroupStep::class,
        Steps\Network\SyncEc2SecurityGroupStep::class,
        //        Steps\Network\SyncRdsSecurityGroupStep::class,
        //
        // sns
        Steps\Network\SyncSnsTopicStep::class,

        // ssh
        Steps\Network\SyncKeyPairStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:network')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync the network resources for the given environment');
    }
}
