<?php

namespace Codinglabs\Yolo\Commands;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\EnvManifest;
use Aws\S3\Exception\S3Exception;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Steps\Build\RetrieveEnvFileStep;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

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
            ->addOption('shared', null, InputOption::VALUE_NONE, 'Push the env-shared files (env manifest + env-shared .env) instead of the app env file')
            ->setDescription('Upload the environment file for the given environment');
    }

    public function handle(): void
    {
        if ($this->option('shared')) {
            $this->pushShared();

            return;
        }

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
                    $differences->map(fn ($key): array => [
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
                info('🐥 Nothing uploaded.');

                return;
            }
        } catch (S3Exception) {
            warning("$filename does not exist in the config bucket.");
        }

        note("Uploading $filename...");

        Aws::s3()
            ->putObject([
                'Body' => file_get_contents($path),
                'Bucket' => Paths::s3ConfigBucket(),
                'Key' => $filename,
            ]);

        unlink(Paths::base($temporaryFilename));

        info('Uploaded successfully.');
    }

    /**
     * Push the environment's own files (whichever exist locally) to the env
     * config bucket: the env manifest — validated before upload so a misshapen
     * manifest never reaches the bucket — and the env-shared .env. Each file
     * shows a key-level diff against the remote and confirms independently.
     */
    protected function pushShared(): void
    {
        $environment = Helpers::environment();
        $manifestPath = EnvManifest::localPath();
        $envPath = Paths::base(".env.{$environment}.shared");

        if (! file_exists($manifestPath) && ! file_exists($envPath)) {
            error(sprintf('Nothing to push — pull first with `yolo env:pull %s --shared`.', $environment));

            return;
        }

        if (file_exists($manifestPath)) {
            $this->pushSharedManifest($manifestPath);
        }

        if (file_exists($envPath)) {
            $this->pushSharedEnv($envPath);
        }
    }

    protected function pushSharedManifest(string $path): void
    {
        try {
            $new = EnvManifest::parse((string) file_get_contents($path));
        } catch (IntegrityCheckException $e) {
            error($e->getMessage());

            return;
        }

        $current = [];

        try {
            $current = EnvManifest::parse($this->remote(EnvManifest::filename()));
        } catch (S3Exception) {
            warning(sprintf('%s does not exist in the env config bucket yet.', EnvManifest::filename()));
        }

        if (! $this->confirmDifferences($this->dot($current), $this->dot($new), EnvManifest::filename())) {
            return;
        }

        $this->upload(EnvManifest::filename(), (string) file_get_contents($path));
    }

    protected function pushSharedEnv(string $path): void
    {
        $current = [];

        try {
            $current = Dotenv::parse($this->remote('.env'));
        } catch (S3Exception) {
            warning('The env-shared .env does not exist in the env config bucket yet.');
        }

        $new = Dotenv::parse((string) file_get_contents($path));

        if (! $this->confirmDifferences($current, $new, 'env-shared .env')) {
            return;
        }

        $this->upload('.env', (string) file_get_contents($path));
    }

    /**
     * Show a key-level current → new diff and ask before uploading. Values are
     * rendered through json_encode so nested manifest values diff and display
     * safely alongside flat env values.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $new
     */
    protected function confirmDifferences(array $current, array $new, string $label): bool
    {
        $differences = collect($current)
            ->diffAssoc($new)
            ->union(collect($new)->diffAssoc($current))
            ->keys();

        if ($differences->isNotEmpty()) {
            table(
                ['Key', 'Current Value', 'New Value'],
                $differences->map(fn ($key): array => [
                    $key,
                    $current[$key] ?? null,
                    $new[$key] ?? null,
                ])->toArray()
            );
        }

        $confirmed = $differences->isEmpty()
            ? confirm(sprintf('No changes detected in %s - do you want to upload anyway?', $label))
            : confirm(sprintf('Are you sure you want to upload these changes to %s?', $label));

        if (! $confirmed) {
            info('🐥 Nothing uploaded.');
        }

        return $confirmed;
    }

    /**
     * Flatten a parsed manifest to dot-keyed scalar strings for diffing —
     * json_encode keeps array leaves (e.g. an empty services map) comparable
     * and printable.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    protected function dot(array $manifest): array
    {
        return collect(Arr::dot($manifest))
            ->map(fn ($value): string => is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value))
            ->all();
    }

    protected function remote(string $key): string
    {
        return (string) Aws::s3()->getObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => $key,
        ])['Body'];
    }

    protected function upload(string $key, string $body): void
    {
        note(sprintf('Uploading %s...', $key === '.env' ? 'env-shared .env' : $key));

        Aws::s3()->putObject([
            'Body' => $body,
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => $key,
        ]);

        info('Uploaded successfully.');
    }
}
