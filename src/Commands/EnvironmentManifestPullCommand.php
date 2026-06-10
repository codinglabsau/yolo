<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\EnvManifest;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Concerns\ManagesEnvironmentFiles;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;

class EnvironmentManifestPullCommand extends Command
{
    use ManagesEnvironmentFiles;

    protected function configure(): void
    {
        $this
            ->setName('environment:manifest:pull')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Download the environment manifest (yolo-{environment}.yml) from the env config bucket');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        note(sprintf('Downloading %s...', EnvManifest::filename()));

        if (! $this->download(EnvManifest::filename(), EnvManifest::localPath())) {
            error(sprintf(
                'No environment manifest found for %s — run `yolo sync:environment %s` to seed it first.',
                $environment,
                $environment,
            ));

            return;
        }

        info('Downloaded successfully');
    }
}
