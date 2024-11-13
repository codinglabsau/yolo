<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

class SyncComputeCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Ensures\EnsureKeyPairExistsStep::class,

        Steps\Compute\SyncLaunchTemplateStep::class,
        Steps\Compute\SyncApplicationLoadBalancerStep::class,
        Steps\Compute\SyncTargetGroupStep::class,
        Steps\Compute\SyncListenerOnPort80Step::class,

        // domain
        Steps\Compute\SyncListenerOnPort443Step::class,
        Steps\Compute\AttachSslCertificateToLoadBalancerListenerStep::class,

        // multitenancy
        Steps\Compute\SyncMultitenancyListenerOnPort443Step::class,
        Steps\Compute\AttachMultitenancySslCertificateToLoadBalancerListenerStep::class,

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
            ->setDescription('Sync configured compute AWS resources');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        intro(sprintf("Executing sync:compute steps in %s", $environment));

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
