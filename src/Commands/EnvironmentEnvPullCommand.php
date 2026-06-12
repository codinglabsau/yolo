<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Paths;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Concerns\ManagesEnvironmentFiles;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;

class EnvironmentEnvPullCommand extends Command
{
    use ManagesEnvironmentFiles;

    protected function configure(): void
    {
        $this
            ->setName('environment:env:pull')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Download the env-shared .env from the env config bucket');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        note(sprintf('Downloading s3://%s/%s → %s...', Paths::s3EnvConfigBucket(), $this->sharedEnvFilename(), $this->sharedEnvFilename()));

        if (! $this->download($this->sharedEnvFilename(), $this->sharedEnvLocalPath())) {
            error(sprintf(
                'No env-shared .env exists yet — create .env.environment.%s locally and push it with `yolo environment:env:push %s`.',
                $environment,
                $environment,
            ));

            return;
        }

        info('Downloaded successfully');
    }
}
