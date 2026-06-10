<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\EnvManifest;
use Aws\S3\Exception\S3Exception;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Steps\Build\RetrieveEnvFileStep;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

class EnvPullCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('env:pull')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('shared', null, InputOption::VALUE_NONE, 'Pull the env-shared files (env manifest + env-shared .env) instead of the app env file')
            ->setDescription('Download the environment file for the given environment');
    }

    public function handle(): void
    {
        if ($this->option('shared')) {
            $this->pullShared();

            return;
        }

        $environment = $this->argument('environment');

        note("Downloading .env.{$environment}...");

        (new RetrieveEnvFileStep())();

        info('Downloaded successfully');
    }

    /**
     * Pull the environment's own files from the env config bucket: the env
     * manifest (must exist — sync seeds it) and the env-shared .env (may not
     * exist yet — it's created by the first push or the first service that
     * generates a secret). Local copies are gitignored; the manifest keeps its
     * bucket name (yolo-{environment}.yml), so a pulled copy can never be
     * pushed at the wrong environment.
     */
    protected function pullShared(): void
    {
        $environment = Helpers::environment();

        note(sprintf('Downloading %s...', EnvManifest::filename()));

        if (! $this->download(EnvManifest::filename(), EnvManifest::localPath())) {
            error(sprintf(
                'No env manifest found for %s — run `yolo sync:environment %s` to seed it first.',
                $environment,
                $environment,
            ));

            return;
        }

        note('Downloading env-shared .env...');

        if (! $this->download('.env', Paths::base(".env.{$environment}.shared"))) {
            warning('No env-shared .env exists yet — it will be created on your first `env:push --shared`.');
        }

        info('Downloaded successfully');
    }

    /**
     * Download one object from the env config bucket, cleaning up the partial
     * file the SDK's SaveAs sink leaves behind when the object is missing.
     */
    protected function download(string $key, string $saveAs): bool
    {
        try {
            Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => $key,
                'SaveAs' => $saveAs,
            ]);

            return true;
        } catch (S3Exception) {
            if (file_exists($saveAs) && filesize($saveAs) === 0) {
                unlink($saveAs);
            }

            return false;
        }
    }
}
