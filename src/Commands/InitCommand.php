<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;

class InitCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Ensures\EnsureManifestExistsStep::class,
        Steps\Ensures\EnsureVpcExistsStep::class,
        Steps\Ensures\EnsureS3ArtefactBucketExistsStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('init')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment name', default: 'production')
            ->setDescription('Create the yolo.yml manifest in the current app root');
    }

    public function handle(): void
    {
        if (Aws::runningInAws()) {
            error("init command cannot be run in AWS.");
            return;
        }

        if (! Helpers::keyedEnv('AWS_PROFILE')) {
            error(sprintf("You need to specify YOLO_%s_AWS_PROFILE in your .env file before proceeding", strtoupper(Helpers::environment())));
        }

        $environment = $this->argument('environment');

        intro(sprintf("Initialising YOLO in %s", $environment));

        info("Executing initialisation steps...");

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
