<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

class NetworkSyncCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Network\SyncVpcStep::class,
        Steps\Network\SyncS3ArtefactBucketStep::class,
        Steps\Network\SyncS3BucketStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('network:sync')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
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
