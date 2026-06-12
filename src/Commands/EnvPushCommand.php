<?php

namespace Codinglabs\Yolo\Commands;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Aws\S3\Exception\S3Exception;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Steps\Build\RetrieveEnvFileStep;
use Codinglabs\Yolo\Concerns\ManagesEnvironmentFiles;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

class EnvPushCommand extends Command
{
    use ManagesEnvironmentFiles;

    protected function configure(): void
    {
        $this
            ->setName('env:push')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Upload the environment file for the given environment');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');
        $filename = ".env.$environment";
        $path = Paths::base($filename);
        $temporaryPath = Paths::base("$filename.tmp");

        if (! file_exists($path)) {
            error("Could not find $filename");

            return;
        }

        $current = [];

        try {
            (new RetrieveEnvFileStep())([
                'save-as' => $temporaryPath,
            ]);

            $current = Dotenv::parse((string) file_get_contents($temporaryPath));
        } catch (S3Exception $e) {
            // Only genuine absence reads as "first push" — a denied or
            // transient read must never silently diff against nothing.
            if (! S3::isNotFound($e)) {
                throw $e;
            }

            warning("$filename does not exist in the config bucket yet.");
        }

        $new = Dotenv::parse((string) file_get_contents($path));

        if (! $this->confirmDifferences($current, $new, $filename)) {
            $this->deleteTemporaryCopy($temporaryPath);

            return;
        }

        note(sprintf('Uploading %s → s3://%s/%s...', $filename, Paths::s3ConfigBucket(), $filename));

        Aws::s3()
            ->putObject([
                'Body' => file_get_contents($path),
                'Bucket' => Paths::s3ConfigBucket(),
                'Key' => $filename,
            ]);

        $this->deleteTemporaryCopy($temporaryPath);

        info('Uploaded successfully.');

        $this->confirmDeleteLocal($path, $filename);
    }

    protected function deleteTemporaryCopy(string $temporaryPath): void
    {
        if (file_exists($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}
