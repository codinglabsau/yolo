<?php

namespace Codinglabs\Yolo\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Steps\Build\RetrieveEnvFileStep;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

class EnvPullCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('env:pull')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Download the environment file for the given environment');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        note("Downloading .env.{$environment}...");

        (new RetrieveEnvFileStep())();

        info('Downloaded successfully');
    }
}
