<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

class SyncNetworkCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Network\SyncVpcStep::class,
        Steps\Network\SyncInternetGatewayStep::class,
        Steps\Network\SyncInternetGatewayAttachmentStep::class,
        Steps\Network\SyncPublicSubnetAStep::class,
        Steps\Network\SyncPublicSubnetBStep::class,
        Steps\Network\SyncPublicSubnetCStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:network')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->setDescription('Sync the network resources for the given environment');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        intro(sprintf("Executing network:sync steps in %s", $environment));

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
