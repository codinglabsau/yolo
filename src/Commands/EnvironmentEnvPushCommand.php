<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Commands;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Aws\S3;
use Aws\S3\Exception\S3Exception;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Concerns\ManagesEnvironmentFiles;

use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

class EnvironmentEnvPushCommand extends Command
{
    use ManagesEnvironmentFiles;

    protected function configure(): void
    {
        $this
            ->setName('environment:env:push')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Upload the env-shared .env to the env config bucket');
    }

    public function handle(): void
    {
        $path = $this->sharedEnvLocalPath();

        if (! file_exists($path)) {
            error(sprintf('Could not find %s', $this->sharedEnvFilename()));

            return;
        }

        $current = [];

        try {
            $current = Dotenv::parse($this->remote($this->sharedEnvFilename()));
        } catch (S3Exception $e) {
            if (! S3::isNotFound($e)) {
                throw $e;
            }

            warning('The env-shared .env does not exist in the env config bucket yet.');
        }

        $new = Dotenv::parse((string) file_get_contents($path));

        if (! $this->confirmDifferences($current, $new, 'the env-shared .env')) {
            return;
        }

        $this->upload($this->sharedEnvFilename(), (string) file_get_contents($path), 'env-shared .env');

        $this->confirmDeleteLocal($path, $this->sharedEnvFilename());
    }
}
