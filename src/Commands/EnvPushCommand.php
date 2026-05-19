<?php

namespace Codinglabs\Yolo\Commands;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Aws\S3\Exception\S3Exception;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Steps\Build\RetrieveEnvFileStep;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class EnvPushCommand extends Command
{
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
        $temporaryFilename = "$filename.tmp";

        if (! file_exists($path)) {
            error("Could not find $filename");

            return;
        }

        try {
            (new RetrieveEnvFileStep())([
                'save-as' => Paths::base($temporaryFilename),
            ]);

            note('Comparing changes...');

            $oldContents = Dotenv::parse(file_get_contents(Paths::base($temporaryFilename)));
            $newContents = Dotenv::parse(file_get_contents($path));
            $differences = collect($oldContents)
                ->diffAssoc($newContents)
                ->union(collect($newContents)->diffAssoc($oldContents))
                ->keys();

            if ($differences->isNotEmpty()) {
                table(
                    ['Key', 'Current Value', 'New Value'],
                    $differences->map(fn ($key) => [
                        $key,
                        $oldContents[$key] ?? null,
                        $newContents[$key] ?? null,
                    ])->toArray()
                );
            }

            $confirm = $differences->isEmpty()
                ? confirm('No changes detected - do you want to upload anyway?')
                : confirm('Are you sure you want to upload these changes?');

            if (! $confirm) {
                unlink(Paths::base($temporaryFilename));
                info('🐥 yolo');

                return;
            }
        } catch (S3Exception $e) {
            warning("$filename does not exist in the artefacts bucket.");
        }

        note("Uploading $filename...");

        Aws::s3()
            ->putObject([
                'Body' => file_get_contents($path),
                'Bucket' => Paths::s3ArtefactsBucket(),
                'Key' => $filename,
            ]);

        unlink(Paths::base($temporaryFilename));

        info('Uploaded successfully.');
    }
}
