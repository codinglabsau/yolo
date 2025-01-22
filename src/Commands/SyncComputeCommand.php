<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncComputeCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Ensures\EnsureKeyPairExistsStep::class,

        Steps\Compute\SyncLaunchTemplateStep::class,
//        Steps\Compute\SyncApplicationLoadBalancerStep::class,
//        Steps\Compute\SyncTargetGroupStep::class,
//        Steps\Compute\SyncListenerOnPort80Step::class,
//
//        // domain
//        Steps\Compute\SyncListenerOnPort443Step::class,
//        Steps\Compute\AttachSslCertificateToLoadBalancerListenerStep::class,
//
//        // multitenancy
//        Steps\Compute\SyncMultitenancyListenerOnPort443Step::class,
//        Steps\Compute\AttachMultitenancySslCertificateToLoadBalancerListenerStep::class,

        // transcoder
        Steps\Compute\SyncElasticTranscoderPipelineStep::class,
        Steps\Compute\SyncElasticTranscoderPresetStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:compute')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync configured compute AWS resources');
    }
}
